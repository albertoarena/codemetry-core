<?php

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\CommitInfo;
use Codemetry\Core\Domain\RepoSnapshot;

test('fromCommits computes totals', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-02T00:00:00+00:00'),
        label: '2024-01-01',
    );

    $commits = [
        new CommitInfo(
            hash: 'aaa',
            authorName: 'Alice',
            authorEmail: 'alice@example.com',
            authoredAt: new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
            subject: 'feat: add feature',
            insertions: 20,
            deletions: 5,
            files: ['src/A.php', 'src/B.php'],
        ),
        new CommitInfo(
            hash: 'bbb',
            authorName: 'Bob',
            authorEmail: 'bob@example.com',
            authoredAt: new DateTimeImmutable('2024-01-01T14:00:00+00:00'),
            subject: 'fix: resolve issue',
            insertions: 3,
            deletions: 1,
            files: ['src/B.php', 'src/C.php'],
        ),
    ];

    $snapshot = RepoSnapshot::fromCommits($window, $commits);

    expect($snapshot->commitsCount)->toBe(2)
        ->and($snapshot->added)->toBe(23)
        ->and($snapshot->deleted)->toBe(6)
        ->and($snapshot->churn)->toBe(29)
        ->and($snapshot->filesTouchedCount)->toBe(3)
        ->and($snapshot->filesTouched)->toContain('src/A.php', 'src/B.php', 'src/C.php');
});

test('fromCommits handles empty commits', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-02T00:00:00+00:00'),
        label: '2024-01-01',
    );

    $snapshot = RepoSnapshot::fromCommits($window, []);

    expect($snapshot->commitsCount)->toBe(0)
        ->and($snapshot->added)->toBe(0)
        ->and($snapshot->deleted)->toBe(0)
        ->and($snapshot->churn)->toBe(0)
        ->and($snapshot->filesTouched)->toBe([]);
});

test('serializes to JSON with totals', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-02T00:00:00+00:00'),
        label: '2024-01-01',
    );

    $snapshot = RepoSnapshot::fromCommits($window, [
        new CommitInfo(
            hash: 'aaa',
            authorName: 'A',
            authorEmail: 'a@a.com',
            authoredAt: new DateTimeImmutable(),
            subject: 'init',
            insertions: 10,
            deletions: 2,
            files: ['file.php'],
        ),
    ]);

    $json = json_decode(json_encode($snapshot), true);

    expect($json['totals']['commits_count'])->toBe(1)
        ->and($json['totals']['added'])->toBe(10)
        ->and($json['totals']['deleted'])->toBe(2)
        ->and($json['totals']['churn'])->toBe(12)
        ->and($json['totals']['files_touched_count'])->toBe(1)
        ->and($json['window']['label'])->toBe('2024-01-01');
});
