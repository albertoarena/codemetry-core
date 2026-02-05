<?php

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\CommitInfo;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Signals\Providers\ChangeShapeProvider;
use Codemetry\Core\Signals\Providers\CommitMessageProvider;
use Codemetry\Core\Signals\ProviderContext;
use Codemetry\Core\Signals\ProviderRegistry;
use Codemetry\Core\Signals\SignalProvider;

test('collects signals from multiple providers', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = RepoSnapshot::fromCommits($window, [
        new CommitInfo(
            hash: 'abc123',
            authorName: 'Test',
            authorEmail: 'test@example.com',
            authoredAt: new DateTimeImmutable('2024-01-15T10:00:00+00:00'),
            subject: 'fix: resolve bug',
            insertions: 10,
            deletions: 2,
            files: ['src/Auth.php'],
        ),
    ]);

    $registry = new ProviderRegistry();
    $registry->register(new ChangeShapeProvider());
    $registry->register(new CommitMessageProvider());

    $ctx = new ProviderContext('/tmp');
    $result = $registry->collect($snapshot, $ctx);

    // Change shape signals
    expect($result['signals']->has('change.churn'))->toBeTrue()
        ->and($result['signals']->has('change.commits_count'))->toBeTrue()
        // Commit message signals
        ->and($result['signals']->has('msg.fix_keyword_count'))->toBeTrue()
        ->and($result['signals']->get('msg.fix_keyword_count')->value)->toBe(1)
        ->and($result['confounders'])->toBe([]);
});

test('catches provider failures and adds confounder', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = RepoSnapshot::fromCommits($window, []);

    $failingProvider = new class implements SignalProvider {
        public function id(): string
        {
            return 'failing_provider';
        }

        public function provide(RepoSnapshot $snapshot, \Codemetry\Core\Signals\ProviderContext $ctx): SignalSet
        {
            throw new \RuntimeException('Tool not found');
        }
    };

    $registry = new ProviderRegistry();
    $registry->register(new ChangeShapeProvider());
    $registry->register($failingProvider);

    $ctx = new ProviderContext('/tmp');
    $result = $registry->collect($snapshot, $ctx);

    expect($result['signals']->has('change.churn'))->toBeTrue()
        ->and($result['confounders'])->toBe(['provider_skipped:failing_provider']);
});

test('lists registered provider ids', function () {
    $registry = new ProviderRegistry();
    $registry->register(new ChangeShapeProvider());
    $registry->register(new CommitMessageProvider());

    expect($registry->ids())->toBe(['change_shape', 'commit_message']);
});
