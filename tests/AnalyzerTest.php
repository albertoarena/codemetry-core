<?php

use Codemetry\Core\Analyzer;
use Codemetry\Core\Domain\AnalysisRequest;
use Codemetry\Core\Domain\AnalysisResult;
use Codemetry\Core\Exception\InvalidRepoException;
use Symfony\Component\Process\Process;

function createAnalyzerTestRepo(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-analyzer-' . uniqid();
    mkdir($dir, 0755, true);

    runGitIn($dir, ['git', 'init']);
    runGitIn($dir, ['git', 'config', 'user.email', 'test@example.com']);
    runGitIn($dir, ['git', 'config', 'user.name', 'Test User']);

    return $dir;
}

function runGitIn(string $dir, array $cmd): void
{
    (new Process($cmd, $dir))->mustRun();
}

function commitFile(string $dir, string $file, string $content, string $message, string $date): void
{
    $fullPath = $dir . '/' . $file;
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    file_put_contents($fullPath, $content);
    runGitIn($dir, ['git', 'add', $file]);

    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ]);

    (new Process(['git', 'commit', '-m', $message], $dir, $env))->mustRun();
}

function destroyRepo(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

// --- End-to-end tests ---

test('produces valid AnalysisResult JSON from a real repo', function () {
    $dir = createAnalyzerTestRepo();

    // Create commits across multiple days
    commitFile($dir, 'src/App.php', "<?php\nclass App {}\n", 'feat: initial app', '2024-01-10T10:00:00+00:00');
    commitFile($dir, 'src/Auth.php', "<?php\nclass Auth {}\n", 'feat: add auth', '2024-01-11T10:00:00+00:00');
    commitFile($dir, 'src/Auth.php', "<?php\nclass Auth { /* fixed */ }\n", 'fix: auth bug', '2024-01-12T10:00:00+00:00');
    commitFile($dir, 'src/Login.php', "<?php\nclass Login {}\n", 'feat: add login', '2024-01-13T10:00:00+00:00');
    commitFile($dir, 'src/App.php', "<?php\nclass App { /* v2 */ }\n", 'fix: app bug', '2024-01-14T10:00:00+00:00');
    commitFile($dir, 'tests/AppTest.php', "<?php\n// tests\n", 'test: add tests', '2024-01-15T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-13T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 5,
        followUpHorizonDays: 2,
    );

    $result = $analyzer->analyze($dir, $request);

    // Verify structure
    expect($result)->toBeInstanceOf(AnalysisResult::class)
        ->and($result->repoId)->not->toBeEmpty()
        ->and($result->requestSummary['baseline_days'])->toBe(5);

    // Verify windows
    expect($result->windows)->toHaveCount(3); // Jan 13, 14, 15

    // Verify JSON output
    $json = json_decode($result->toJson(), true);

    expect($json['schema_version'])->toBe('1.0')
        ->and($json['windows'])->toHaveCount(3);

    // Each window should have required fields
    foreach ($json['windows'] as $window) {
        expect($window)->toHaveKeys([
            'window_label', 'mood_label', 'mood_score', 'confidence',
            'reasons', 'confounders', 'raw_signals', 'normalized',
        ]);

        expect($window['mood_label'])->toBeIn(['bad', 'medium', 'good'])
            ->and($window['mood_score'])->toBeGreaterThanOrEqual(0)
            ->and($window['mood_score'])->toBeLessThanOrEqual(100)
            ->and($window['confidence'])->toBeGreaterThanOrEqual(0.0)
            ->and($window['confidence'])->toBeLessThanOrEqual(1.0);
    }

    // Window labels should be correct dates
    expect($json['windows'][0]['window_label'])->toBe('2024-01-13')
        ->and($json['windows'][1]['window_label'])->toBe('2024-01-14')
        ->and($json['windows'][2]['window_label'])->toBe('2024-01-15');

    // Raw signals should be present
    $firstWindow = $json['windows'][0];
    expect($firstWindow['raw_signals']['signals'])->toHaveKey('change.churn')
        ->and($firstWindow['raw_signals']['signals'])->toHaveKey('change.commits_count')
        ->and($firstWindow['raw_signals']['signals'])->toHaveKey('msg.fix_keyword_count');

    destroyRepo($dir);
});

test('handles days parameter', function () {
    $dir = createAnalyzerTestRepo();

    commitFile($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitFile($dir, 'file.txt', 'content', 'feat: add file', '2024-01-14T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        days: 3,
        baselineDays: 5,
    );

    $result = $analyzer->analyze($dir, $request);

    // 3 days: Jan 13, 14, 15
    expect($result->windows)->toHaveCount(3)
        ->and($result->windows[0]->windowLabel)->toBe('2024-01-13')
        ->and($result->windows[2]->windowLabel)->toBe('2024-01-15');

    destroyRepo($dir);
});

test('filters by author', function () {
    $dir = createAnalyzerTestRepo();

    commitFile($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');

    // Alice's commit
    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => '2024-01-15T10:00:00+00:00',
        'GIT_COMMITTER_DATE' => '2024-01-15T10:00:00+00:00',
        'GIT_AUTHOR_NAME' => 'Alice',
        'GIT_AUTHOR_EMAIL' => 'alice@example.com',
    ]);
    file_put_contents($dir . '/alice.txt', 'alice work');
    runGitIn($dir, ['git', 'add', 'alice.txt']);
    (new Process(['git', 'commit', '-m', 'feat: alice work'], $dir, $env))->mustRun();

    // Bob's commit
    $env['GIT_AUTHOR_NAME'] = 'Bob';
    $env['GIT_AUTHOR_EMAIL'] = 'bob@example.com';
    file_put_contents($dir . '/bob.txt', 'bob work');
    runGitIn($dir, ['git', 'add', 'bob.txt']);
    (new Process(['git', 'commit', '-m', 'feat: bob work'], $dir, $env))->mustRun();

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        author: 'Alice',
        baselineDays: 3,
    );

    $result = $analyzer->analyze($dir, $request);

    // Only Alice's commit should appear
    $signals = $result->windows[0]->rawSignals;
    expect($signals->get('change.commits_count')->value)->toBe(1);

    destroyRepo($dir);
});

test('handles empty windows gracefully', function () {
    $dir = createAnalyzerTestRepo();

    commitFile($dir, 'init.txt', 'init', 'init', '2024-01-01T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-17T00:00:00+00:00'),
        baselineDays: 3,
    );

    $result = $analyzer->analyze($dir, $request);

    expect($result->windows)->toHaveCount(2);

    foreach ($result->windows as $mood) {
        expect($mood->rawSignals->get('change.commits_count')->value)->toBe(0)
            ->and($mood->moodScore)->toBeGreaterThanOrEqual(0);
    }

    destroyRepo($dir);
});

test('throws for invalid repo path', function () {
    $analyzer = new Analyzer();
    $request = new AnalysisRequest(days: 7);

    $analyzer->analyze('/nonexistent/path', $request);
})->throws(InvalidRepoException::class);

test('uses baseline cache on second run', function () {
    $dir = createAnalyzerTestRepo();

    commitFile($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitFile($dir, 'file.txt', 'content', 'feat: add', '2024-01-15T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 3,
    );

    // First run — builds and caches baseline
    $result1 = $analyzer->analyze($dir, $request);

    // Verify cache file exists
    $cachePath = $dir . '/.git/codemetry/cache-baseline.json';
    expect(file_exists($cachePath))->toBeTrue();

    // Second run — should use cache
    $result2 = $analyzer->analyze($dir, $request);

    expect($result2->windows)->toHaveCount(1)
        ->and($result2->windows[0]->moodScore)->toBe($result1->windows[0]->moodScore);

    destroyRepo($dir);
});

test('JSON output matches schema version 1.0', function () {
    $dir = createAnalyzerTestRepo();

    commitFile($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    commitFile($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');
    commitFile($dir, 'file.php', "<?php\necho 2;\n", 'fix: typo', '2024-01-15T14:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 3,
    );

    $result = $analyzer->analyze($dir, $request);
    $json = json_decode($result->toJson(JSON_PRETTY_PRINT), true);

    // Top-level structure
    expect($json)->toHaveKeys(['schema_version', 'repo_id', 'analyzed_at', 'request_summary', 'windows'])
        ->and($json['schema_version'])->toBe('1.0');

    // Window structure
    $w = $json['windows'][0];
    expect($w)->toHaveKeys([
        'window_label', 'mood_label', 'mood_score', 'confidence',
        'reasons', 'confounders', 'raw_signals', 'normalized',
    ]);

    // Signals present
    expect($w['raw_signals']['signals'])->toHaveKeys([
        'change.added', 'change.deleted', 'change.churn',
        'change.commits_count', 'change.files_touched',
        'change.churn_per_commit', 'change.scatter',
        'msg.fix_keyword_count', 'msg.revert_count',
        'msg.wip_count', 'msg.fix_ratio',
        'followup.horizon_days', 'followup.touching_commits',
        'followup.fix_commits', 'followup.fix_density',
    ]);

    // Commits count should be 2 (feat + fix)
    expect($w['raw_signals']['signals']['change.commits_count']['value'])->toBe(2)
        ->and($w['raw_signals']['signals']['msg.fix_keyword_count']['value'])->toBe(1);

    destroyRepo($dir);
});
