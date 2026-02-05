<?php

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\CommitInfo;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Signals\Providers\CommitMessageProvider;
use Codemetry\Core\Signals\ProviderContext;

function makeSnapshotWithMessages(array $messages): RepoSnapshot
{
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $commits = array_map(
        fn(string $msg) => new CommitInfo(
            hash: bin2hex(random_bytes(8)),
            authorName: 'Test',
            authorEmail: 'test@example.com',
            authoredAt: new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            subject: $msg,
            insertions: 1,
            deletions: 0,
            files: ['file.php'],
        ),
        $messages,
    );

    return RepoSnapshot::fromCommits($window, $commits);
}

test('detects fix keywords', function () {
    $snapshot = makeSnapshotWithMessages([
        'fix: resolve login issue',
        'feat: add dashboard',
        'bug: wrong calculation',
        'hotfix: critical error',
        'chore: update deps',
    ]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($provider->id())->toBe('commit_message')
        ->and($signals->get('msg.fix_keyword_count')->value)->toBe(3)
        ->and($signals->get('msg.fix_ratio')->value)->toBe(0.6);
});

test('detects revert keywords', function () {
    $snapshot = makeSnapshotWithMessages([
        'revert: undo login change',
        'feat: add feature',
        'Revert "add broken feature"',
    ]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('msg.revert_count')->value)->toBe(2);
});

test('detects wip keywords', function () {
    $snapshot = makeSnapshotWithMessages([
        'wip: work in progress',
        'tmp: temporary fix',
        'debug: add logging',
        'hack: workaround for issue',
        'feat: normal commit',
    ]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('msg.wip_count')->value)->toBe(4);
});

test('handles no commits', function () {
    $snapshot = makeSnapshotWithMessages([]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('msg.fix_keyword_count')->value)->toBe(0)
        ->and($signals->get('msg.revert_count')->value)->toBe(0)
        ->and($signals->get('msg.wip_count')->value)->toBe(0)
        ->and($signals->get('msg.fix_ratio')->value)->toBe(0.0);
});

test('detects all keyword variants', function () {
    $snapshot = makeSnapshotWithMessages([
        'fix: something',
        'bug: found issue',
        'hotfix: urgent',
        'patch: update',
        'typo: wrong name',
        'oops: mistake',
    ]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('msg.fix_keyword_count')->value)->toBe(6)
        ->and($signals->get('msg.fix_ratio')->value)->toBe(1.0);
});

test('keyword matching is case insensitive', function () {
    $snapshot = makeSnapshotWithMessages([
        'FIX: uppercase fix',
        'Bug: capitalized bug',
        'REVERT: uppercase revert',
        'WIP: uppercase wip',
    ]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    expect($signals->get('msg.fix_keyword_count')->value)->toBe(2)
        ->and($signals->get('msg.revert_count')->value)->toBe(1)
        ->and($signals->get('msg.wip_count')->value)->toBe(1);
});

test('does not match partial words', function () {
    $snapshot = makeSnapshotWithMessages([
        'refixture: partial word only',
        'prefix: partial word only',
        'debugging: partial word only',
    ]);

    $provider = new CommitMessageProvider();
    $ctx = new ProviderContext('/tmp');
    $signals = $provider->provide($snapshot, $ctx);

    // \bfix\b should not match "refixture" or "prefix"
    // \bdebug\b should not match "debugging"
    expect($signals->get('msg.fix_keyword_count')->value)->toBe(0)
        ->and($signals->get('msg.wip_count')->value)->toBe(0);
});
