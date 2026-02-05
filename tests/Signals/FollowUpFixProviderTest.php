<?php

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\CommitInfo;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Git\GitRepoReader;
use Codemetry\Core\Signals\Providers\FollowUpFixProvider;
use Codemetry\Core\Signals\ProviderContext;
use Symfony\Component\Process\Process;

function createTestRepo(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-followup-' . uniqid();
    mkdir($dir, 0755, true);

    gitRun($dir, ['git', 'init']);
    gitRun($dir, ['git', 'config', 'user.email', 'test@example.com']);
    gitRun($dir, ['git', 'config', 'user.name', 'Test User']);

    return $dir;
}

function gitRun(string $dir, array $cmd): void
{
    (new Process($cmd, $dir))->mustRun();
}

function gitCommit(string $dir, string $file, string $content, string $message, string $date): void
{
    $fullPath = $dir . '/' . $file;
    $parentDir = dirname($fullPath);
    if (!is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    file_put_contents($fullPath, $content);
    gitRun($dir, ['git', 'add', $file]);

    $env = array_merge($_ENV, [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ]);

    (new Process(['git', 'commit', '-m', $message], $dir, $env))->mustRun();
}

function destroyTestRepo(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

// --- Integration tests with real git repos ---

test('detects follow-up fix commits in horizon', function () {
    $dir = createTestRepo();

    // Window commits (Jan 15)
    gitCommit($dir, 'src/Auth.php', "<?php\nclass Auth {}\n", 'feat: add auth', '2024-01-15T10:00:00+00:00');
    gitCommit($dir, 'src/Login.php', "<?php\nclass Login {}\n", 'feat: add login', '2024-01-15T14:00:00+00:00');

    // Horizon commits (Jan 16-18, within 3-day horizon)
    gitCommit($dir, 'src/Auth.php', "<?php\nclass Auth { /* fixed */ }\n", 'fix: auth bug', '2024-01-16T10:00:00+00:00');
    gitCommit($dir, 'src/Login.php', "<?php\nclass Login { /* v2 */ }\n", 'feat: improve login', '2024-01-17T10:00:00+00:00');
    gitCommit($dir, 'src/Other.php', "<?php\n", 'feat: unrelated', '2024-01-17T12:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = $reader->buildSnapshot($dir, $window);
    $ctx = new ProviderContext($dir, ['follow_up_horizon_days' => 3], $reader);

    $provider = new FollowUpFixProvider();
    $signals = $provider->provide($snapshot, $ctx);

    expect($provider->id())->toBe('follow_up_fix')
        ->and($signals->get('followup.horizon_days')->value)->toBe(3)
        ->and($signals->get('followup.touching_commits')->value)->toBe(2) // auth fix + login improve
        ->and($signals->get('followup.fix_commits')->value)->toBe(1);    // only auth fix

    destroyTestRepo($dir);
});

test('fix density accounts for churn', function () {
    $dir = createTestRepo();

    // Window: high churn
    gitCommit($dir, 'big.php', str_repeat("line\n", 100), 'feat: big file', '2024-01-15T10:00:00+00:00');

    // Horizon: one fix
    gitCommit($dir, 'big.php', str_repeat("fixed\n", 100), 'fix: correct big file', '2024-01-16T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = $reader->buildSnapshot($dir, $window);
    $ctx = new ProviderContext($dir, ['follow_up_horizon_days' => 3], $reader);

    $provider = new FollowUpFixProvider();
    $signals = $provider->provide($snapshot, $ctx);

    // fix_density = 1 / max(1, churn=100) = 0.01
    expect($signals->get('followup.fix_commits')->value)->toBe(1)
        ->and($signals->get('followup.fix_density')->value)->toBe(0.01);

    destroyTestRepo($dir);
});

test('returns zeros when no horizon commits touch window files', function () {
    $dir = createTestRepo();

    gitCommit($dir, 'src/A.php', "<?php\n", 'feat: add A', '2024-01-15T10:00:00+00:00');

    // Horizon commit touches a different file
    gitCommit($dir, 'src/B.php', "<?php\n", 'fix: unrelated file', '2024-01-16T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = $reader->buildSnapshot($dir, $window);
    $ctx = new ProviderContext($dir, ['follow_up_horizon_days' => 3], $reader);

    $provider = new FollowUpFixProvider();
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('followup.touching_commits')->value)->toBe(0)
        ->and($signals->get('followup.fix_commits')->value)->toBe(0)
        ->and($signals->get('followup.fix_density')->value)->toBe(0.0);

    destroyTestRepo($dir);
});

test('returns zeros when snapshot has no files', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = RepoSnapshot::fromCommits($window, []);

    $provider = new FollowUpFixProvider();
    $ctx = new ProviderContext('/tmp', ['follow_up_horizon_days' => 3], new GitRepoReader());
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('followup.touching_commits')->value)->toBe(0)
        ->and($signals->get('followup.fix_commits')->value)->toBe(0)
        ->and($signals->get('followup.fix_density')->value)->toBe(0.0);
});

test('returns zeros when git reader is not available', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = RepoSnapshot::fromCommits($window, [
        new CommitInfo(
            hash: 'abc',
            authorName: 'Test',
            authorEmail: 'test@example.com',
            authoredAt: new DateTimeImmutable(),
            subject: 'feat: something',
            insertions: 10,
            deletions: 0,
            files: ['file.php'],
        ),
    ]);

    $provider = new FollowUpFixProvider();
    $ctx = new ProviderContext('/tmp'); // no git reader
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('followup.touching_commits')->value)->toBe(0)
        ->and($signals->get('followup.fix_commits')->value)->toBe(0);
});

test('respects configurable horizon days', function () {
    $dir = createTestRepo();

    gitCommit($dir, 'src/A.php', "<?php\nv1\n", 'feat: add A', '2024-01-15T10:00:00+00:00');

    // Day 1 after window — within 1-day horizon
    gitCommit($dir, 'src/A.php', "<?php\nv2\n", 'fix: A bug', '2024-01-16T10:00:00+00:00');

    // Day 3 after window — outside 1-day horizon
    gitCommit($dir, 'src/A.php', "<?php\nv3\n", 'fix: another A bug', '2024-01-18T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = $reader->buildSnapshot($dir, $window);

    // 1-day horizon: only the first fix
    $ctx1 = new ProviderContext($dir, ['follow_up_horizon_days' => 1], $reader);
    $signals1 = (new FollowUpFixProvider())->provide($snapshot, $ctx1);

    expect($signals1->get('followup.horizon_days')->value)->toBe(1)
        ->and($signals1->get('followup.fix_commits')->value)->toBe(1);

    // 5-day horizon: both fixes
    $ctx5 = new ProviderContext($dir, ['follow_up_horizon_days' => 5], $reader);
    $signals5 = (new FollowUpFixProvider())->provide($snapshot, $ctx5);

    expect($signals5->get('followup.horizon_days')->value)->toBe(5)
        ->and($signals5->get('followup.fix_commits')->value)->toBe(2);

    destroyTestRepo($dir);
});
