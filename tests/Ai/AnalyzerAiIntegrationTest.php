<?php

use Codemetry\Core\Analyzer;
use Codemetry\Core\Domain\AnalysisRequest;
use Symfony\Component\Process\Process;

function createAiTestRepo(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-ai-test-' . uniqid();
    mkdir($dir, 0755, true);

    runGitInDir($dir, ['git', 'init']);
    runGitInDir($dir, ['git', 'config', 'user.email', 'test@example.com']);
    runGitInDir($dir, ['git', 'config', 'user.name', 'Test User']);

    return $dir;
}

function runGitInDir(string $dir, array $cmd): void
{
    (new Process($cmd, $dir))->mustRun();
}

function gitCommitInDir(string $dir, string $file, string $content, string $message, string $date): void
{
    $fullPath = $dir . '/' . $file;
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    file_put_contents($fullPath, $content);
    runGitInDir($dir, ['git', 'add', $file]);

    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ]);

    (new Process(['git', 'commit', '-m', $message], $dir, $env))->mustRun();
}

function cleanupAiTestRepo(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

test('ai disabled by default does not add confounder', function () {
    $dir = createAiTestRepo();

    gitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    gitCommitInDir($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 3,
        aiEnabled: false,
    );

    $result = $analyzer->analyze($dir, $request);

    expect($result->windows[0]->confounders)->not->toContain('ai_unavailable');

    cleanupAiTestRepo($dir);
});

test('ai enabled without api key does not crash', function () {
    $dir = createAiTestRepo();

    gitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    gitCommitInDir($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 3,
        aiEnabled: true,
        aiEngine: 'openai',
    );

    // No API key provided - should gracefully handle
    $result = $analyzer->analyze($dir, $request, ['ai' => ['api_key' => '']]);

    // Should still produce valid results
    expect($result->windows)->toHaveCount(1)
        ->and($result->windows[0]->moodScore)->toBeGreaterThanOrEqual(0)
        ->and($result->windows[0]->moodScore)->toBeLessThanOrEqual(100);

    cleanupAiTestRepo($dir);
});

test('ai engine switchable via request', function () {
    $dir = createAiTestRepo();

    gitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    gitCommitInDir($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    $analyzer = new Analyzer();

    // Test with different engines - all should gracefully handle missing API keys
    foreach (['openai', 'anthropic', 'deepseek', 'google'] as $engine) {
        $request = new AnalysisRequest(
            since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
            until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
            baselineDays: 3,
            aiEnabled: true,
            aiEngine: $engine,
        );

        $result = $analyzer->analyze($dir, $request, ['ai' => ['api_key' => '']]);

        expect($result->windows)->toHaveCount(1);
    }

    cleanupAiTestRepo($dir);
});

test('mood result includes ai summary field when ai enabled', function () {
    $dir = createAiTestRepo();

    gitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    gitCommitInDir($dir, 'file.php', "<?php\necho 1;\n", 'feat: add file', '2024-01-15T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 3,
        aiEnabled: false,
    );

    $result = $analyzer->analyze($dir, $request);
    $json = json_decode($result->toJson(), true);

    // AI summary should not be present when AI is disabled
    expect($json['windows'][0])->not->toHaveKey('ai_summary');

    cleanupAiTestRepo($dir);
});
