<?php

use Codemetry\Core\Analyzer;
use Codemetry\Core\Domain\AnalysisRequest;
use Symfony\Component\Process\Process;

// Reuse helper functions from AnalyzerAiIntegrationTest.php
function createBatchTestRepo(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-batch-test-' . uniqid();
    mkdir($dir, 0755, true);

    runBatchGitInDir($dir, ['git', 'init']);
    runBatchGitInDir($dir, ['git', 'config', 'user.email', 'test@example.com']);
    runBatchGitInDir($dir, ['git', 'config', 'user.name', 'Test User']);

    return $dir;
}

function runBatchGitInDir(string $dir, array $cmd): void
{
    (new Process($cmd, $dir))->mustRun();
}

function batchGitCommitInDir(string $dir, string $file, string $content, string $message, string $date): void
{
    $fullPath = $dir . '/' . $file;
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    file_put_contents($fullPath, $content);
    runBatchGitInDir($dir, ['git', 'add', $file]);

    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ]);

    (new Process(['git', 'commit', '-m', $message], $dir, $env))->mustRun();
}

function cleanupBatchTestRepo(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

test('analyzer uses batch mode when batch_size is set', function () {
    $dir = createBatchTestRepo();

    // Create commits for multiple days
    batchGitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-10T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file1.php', "<?php\necho 1;\n", 'feat: day 1', '2024-01-15T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file2.php', "<?php\necho 2;\n", 'feat: day 2', '2024-01-16T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file3.php', "<?php\necho 3;\n", 'feat: day 3', '2024-01-17T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-18T00:00:00+00:00'),
        baselineDays: 3,
        aiEnabled: true,
    );

    // batch_size: 2 means 3 days should require 2 API calls (2 + 1)
    // No real API key, so AI will gracefully fail
    $result = $analyzer->analyze($dir, $request, [
        'ai' => [
            'api_key' => '',
            'batch_size' => 2,
        ],
    ]);

    expect($result->windows)->toHaveCount(3);

    cleanupBatchTestRepo($dir);
});

test('analyzer respects default batch_size of 10', function () {
    $dir = createBatchTestRepo();

    batchGitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-01T10:00:00+00:00');

    // Create 5 days of commits
    for ($i = 1; $i <= 5; $i++) {
        $date = sprintf('2024-01-1%d', $i);
        batchGitCommitInDir($dir, "file{$i}.php", "<?php\necho {$i};\n", "feat: day {$i}", "{$date}T10:00:00+00:00");
    }

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-11T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        baselineDays: 7,
        aiEnabled: true,
    );

    // Default batch_size is 10, so 5 days = 1 batch
    $result = $analyzer->analyze($dir, $request, [
        'ai' => ['api_key' => ''],
    ]);

    expect($result->windows)->toHaveCount(5);

    cleanupBatchTestRepo($dir);
});

test('analyzer without ai enabled processes all days', function () {
    $dir = createBatchTestRepo();

    batchGitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-01T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file1.php', "<?php\n", 'feat: day 1', '2024-01-10T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file2.php', "<?php\n", 'feat: day 2', '2024-01-11T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file3.php', "<?php\n", 'feat: day 3', '2024-01-12T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-10T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-13T00:00:00+00:00'),
        baselineDays: 5,
        aiEnabled: false,
    );

    $result = $analyzer->analyze($dir, $request);

    expect($result->windows)->toHaveCount(3)
        ->and($result->windows[0]->windowLabel)->toBe('2024-01-10')
        ->and($result->windows[1]->windowLabel)->toBe('2024-01-11')
        ->and($result->windows[2]->windowLabel)->toBe('2024-01-12');

    cleanupBatchTestRepo($dir);
});

test('batch_size of 1 processes one day at a time', function () {
    $dir = createBatchTestRepo();

    batchGitCommitInDir($dir, 'init.txt', 'init', 'init', '2024-01-01T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file1.php', "<?php\n", 'feat: day 1', '2024-01-10T10:00:00+00:00');
    batchGitCommitInDir($dir, 'file2.php', "<?php\n", 'feat: day 2', '2024-01-11T10:00:00+00:00');

    $analyzer = new Analyzer();
    $request = new AnalysisRequest(
        since: new DateTimeImmutable('2024-01-10T00:00:00+00:00'),
        until: new DateTimeImmutable('2024-01-12T00:00:00+00:00'),
        baselineDays: 5,
        aiEnabled: true,
    );

    // batch_size: 1 means each day is processed individually
    $result = $analyzer->analyze($dir, $request, [
        'ai' => [
            'api_key' => '',
            'batch_size' => 1,
        ],
    ]);

    expect($result->windows)->toHaveCount(2);

    cleanupBatchTestRepo($dir);
});
