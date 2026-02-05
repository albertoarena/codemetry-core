<?php

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\CommitInfo;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Signals\Providers\ChangeShapeProvider;
use Codemetry\Core\Signals\ProviderContext;

function makeSnapshot(array $commits = [], string $label = '2024-01-15'): RepoSnapshot
{
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: $label,
    );

    return RepoSnapshot::fromCommits($window, $commits);
}

function makeCommit(
    string $subject = 'feat: something',
    int $insertions = 10,
    int $deletions = 2,
    array $files = ['src/File.php'],
): CommitInfo {
    return new CommitInfo(
        hash: bin2hex(random_bytes(8)),
        authorName: 'Test',
        authorEmail: 'test@example.com',
        authoredAt: new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
        subject: $subject,
        insertions: $insertions,
        deletions: $deletions,
        files: $files,
    );
}

test('produces all change shape signals', function () {
    $snapshot = makeSnapshot([
        makeCommit('feat: add login', 20, 5, ['src/Auth.php', 'src/Controller.php']),
        makeCommit('feat: add logout', 10, 3, ['src/Auth.php', 'tests/AuthTest.php']),
    ]);

    $provider = new ChangeShapeProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($provider->id())->toBe('change_shape')
        ->and($signals->get('change.added')->value)->toBe(30)
        ->and($signals->get('change.deleted')->value)->toBe(8)
        ->and($signals->get('change.churn')->value)->toBe(38)
        ->and($signals->get('change.commits_count')->value)->toBe(2)
        ->and($signals->get('change.files_touched')->value)->toBe(3)
        ->and($signals->get('change.churn_per_commit')->value)->toBe(19.0)
        ->and($signals->get('change.scatter')->value)->toBe(2); // src/ and tests/
});

test('handles empty snapshot', function () {
    $snapshot = makeSnapshot([]);

    $provider = new ChangeShapeProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('change.added')->value)->toBe(0)
        ->and($signals->get('change.deleted')->value)->toBe(0)
        ->and($signals->get('change.churn')->value)->toBe(0)
        ->and($signals->get('change.commits_count')->value)->toBe(0)
        ->and($signals->get('change.files_touched')->value)->toBe(0)
        ->and($signals->get('change.churn_per_commit')->value)->toBe(0.0)
        ->and($signals->get('change.scatter')->value)->toBe(0);
});

test('scatter counts unique directories', function () {
    $snapshot = makeSnapshot([
        makeCommit('feat: wide change', 50, 10, [
            'src/Auth/Login.php',
            'src/Auth/Logout.php',
            'src/Http/Controller.php',
            'tests/Auth/LoginTest.php',
            'config/auth.php',
        ]),
    ]);

    $provider = new ChangeShapeProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    // src/Auth, src/Http, tests/Auth, config
    expect($signals->get('change.scatter')->value)->toBe(4);
});

test('scatter treats root files correctly', function () {
    $snapshot = makeSnapshot([
        makeCommit('chore: update config', 5, 1, ['README.md', '.gitignore']),
    ]);

    $provider = new ChangeShapeProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    // Both are in "." directory
    expect($signals->get('change.scatter')->value)->toBe(1);
});
