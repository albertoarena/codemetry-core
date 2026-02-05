<?php

use Codemetry\Core\Domain\CommitInfo;

test('stores commit data', function () {
    $commit = new CommitInfo(
        hash: 'abc123',
        authorName: 'Alice',
        authorEmail: 'alice@example.com',
        authoredAt: new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
        subject: 'fix: resolve login bug',
        insertions: 10,
        deletions: 3,
        files: ['src/Auth.php', 'tests/AuthTest.php'],
    );

    expect($commit->hash)->toBe('abc123')
        ->and($commit->authorName)->toBe('Alice')
        ->and($commit->insertions)->toBe(10)
        ->and($commit->deletions)->toBe(3)
        ->and($commit->files)->toHaveCount(2);
});

test('serializes to JSON', function () {
    $commit = new CommitInfo(
        hash: 'def456',
        authorName: 'Bob',
        authorEmail: 'bob@example.com',
        authoredAt: new DateTimeImmutable('2024-06-15T14:30:00+00:00'),
        subject: 'feat: add dashboard',
        insertions: 50,
        deletions: 0,
        files: ['src/Dashboard.php'],
    );

    $json = json_decode(json_encode($commit), true);

    expect($json['hash'])->toBe('def456')
        ->and($json['author_name'])->toBe('Bob')
        ->and($json['authored_at'])->toBe('2024-06-15T14:30:00+00:00')
        ->and($json['insertions'])->toBe(50)
        ->and($json['files'])->toBe(['src/Dashboard.php']);
});

test('defaults insertions and deletions to zero', function () {
    $commit = new CommitInfo(
        hash: 'aaa',
        authorName: 'X',
        authorEmail: 'x@x.com',
        authoredAt: new DateTimeImmutable(),
        subject: 'init',
    );

    expect($commit->insertions)->toBe(0)
        ->and($commit->deletions)->toBe(0)
        ->and($commit->files)->toBe([]);
});
