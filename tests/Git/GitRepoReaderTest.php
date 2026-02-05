<?php

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Exception\GitCommandException;
use Codemetry\Core\Exception\InvalidRepoException;
use Codemetry\Core\Git\GitRepoReader;

function createTempGitRepo(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-test-' . uniqid();
    mkdir($dir, 0755, true);

    runInDir($dir, ['git', 'init']);
    runInDir($dir, ['git', 'config', 'user.email', 'test@example.com']);
    runInDir($dir, ['git', 'config', 'user.name', 'Test User']);

    return $dir;
}

function runInDir(string $dir, array $cmd): void
{
    $process = new Symfony\Component\Process\Process($cmd, $dir);
    $process->mustRun();
}

function addCommit(
    string $dir,
    string $filename,
    string $content,
    string $message,
    string $date = '2024-01-15T10:00:00+00:00',
    ?string $author = null,
): void {
    file_put_contents($dir . '/' . $filename, $content);
    runInDir($dir, ['git', 'add', $filename]);

    $env = [
        'GIT_AUTHOR_DATE' => $date,
        'GIT_COMMITTER_DATE' => $date,
    ];

    if ($author !== null) {
        $env['GIT_AUTHOR_NAME'] = $author;
        $env['GIT_AUTHOR_EMAIL'] = $author . '@example.com';
    }

    $process = new Symfony\Component\Process\Process(
        ['git', 'commit', '-m', $message],
        $dir,
        $env + $_ENV,
    );
    $process->mustRun();
}

function cleanupTempRepo(string $dir): void
{
    $process = new Symfony\Component\Process\Process(['rm', '-rf', $dir]);
    $process->run();
}

// --- Validation tests ---

test('validates a valid git repo', function () {
    $dir = createTempGitRepo();
    addCommit($dir, 'init.txt', 'hello', 'initial commit');

    $reader = new GitRepoReader();
    $reader->validateRepo($dir);

    expect(true)->toBeTrue(); // no exception thrown

    cleanupTempRepo($dir);
});

test('throws for non-existent path', function () {
    $reader = new GitRepoReader();
    $reader->validateRepo('/nonexistent/path/xyz');
})->throws(InvalidRepoException::class, 'Path does not exist');

test('throws for non-git directory', function () {
    $dir = sys_get_temp_dir() . '/codemetry-notgit-' . uniqid();
    mkdir($dir, 0755, true);

    try {
        $reader = new GitRepoReader();
        $reader->validateRepo($dir);
    } finally {
        rmdir($dir);
    }
})->throws(InvalidRepoException::class, 'not inside a Git repository');

// --- Commit listing tests ---

test('lists commits within a window', function () {
    $dir = createTempGitRepo();

    addCommit($dir, 'a.php', '<?php echo 1;', 'feat: add a', '2024-01-15T10:00:00+00:00');
    addCommit($dir, 'b.php', '<?php echo 2;', 'feat: add b', '2024-01-15T14:00:00+00:00');
    addCommit($dir, 'c.php', '<?php echo 3;', 'feat: add c', '2024-01-16T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $commits = $reader->getCommits($dir, $window);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->subject)->toBe('feat: add b')
        ->and($commits[1]->subject)->toBe('feat: add a');

    cleanupTempRepo($dir);
});

test('returns empty array when no commits in window', function () {
    $dir = createTempGitRepo();
    addCommit($dir, 'a.php', '<?php', 'init', '2024-01-10T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $commits = $reader->getCommits($dir, $window);

    expect($commits)->toBe([]);

    cleanupTempRepo($dir);
});

test('filters commits by author', function () {
    $dir = createTempGitRepo();

    addCommit($dir, 'a.php', '<?php echo 1;', 'feat: alice work', '2024-01-15T10:00:00+00:00', 'alice');
    addCommit($dir, 'b.php', '<?php echo 2;', 'feat: bob work', '2024-01-15T14:00:00+00:00', 'bob');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $commits = $reader->getCommits($dir, $window, author: 'alice');

    expect($commits)->toHaveCount(1)
        ->and($commits[0]->authorName)->toBe('alice')
        ->and($commits[0]->subject)->toBe('feat: alice work');

    cleanupTempRepo($dir);
});

// --- Numstat tests ---

test('parses numstat for insertions and deletions', function () {
    $dir = createTempGitRepo();
    addCommit($dir, 'file.php', "line1\nline2\nline3\n", 'add file');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $commits = $reader->getCommits($dir, $window);

    expect($commits)->toHaveCount(1)
        ->and($commits[0]->insertions)->toBe(3)
        ->and($commits[0]->deletions)->toBe(0)
        ->and($commits[0]->files)->toBe(['file.php']);

    cleanupTempRepo($dir);
});

test('counts insertions and deletions on file modification', function () {
    $dir = createTempGitRepo();
    addCommit($dir, 'file.php', "line1\nline2\nline3\n", 'add file', '2024-01-15T09:00:00+00:00');
    addCommit($dir, 'file.php', "line1\nmodified\nline3\nnew_line\n", 'edit file', '2024-01-15T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T09:30:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $commits = $reader->getCommits($dir, $window);

    expect($commits)->toHaveCount(1)
        ->and($commits[0]->insertions)->toBe(2)
        ->and($commits[0]->deletions)->toBe(1)
        ->and($commits[0]->files)->toBe(['file.php']);

    cleanupTempRepo($dir);
});

// --- Snapshot builder tests ---

test('builds snapshot with correct totals', function () {
    $dir = createTempGitRepo();

    addCommit($dir, 'a.php', "line1\nline2\n", 'add a', '2024-01-15T10:00:00+00:00');
    addCommit($dir, 'b.php', "x\ny\nz\n", 'add b', '2024-01-15T12:00:00+00:00');
    addCommit($dir, 'a.php', "line1\nmodified\nnew\n", 'edit a', '2024-01-15T14:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = $reader->buildSnapshot($dir, $window);

    expect($snapshot->commitsCount)->toBe(3)
        ->and($snapshot->filesTouchedCount)->toBe(2)
        ->and($snapshot->filesTouched)->toContain('a.php', 'b.php')
        ->and($snapshot->added)->toBe(7)   // 2 + 3 + 2
        ->and($snapshot->deleted)->toBe(1) // 0 + 0 + 1
        ->and($snapshot->churn)->toBe(8)   // 7 + 1
        ->and($snapshot->window->label)->toBe('2024-01-15');

    cleanupTempRepo($dir);
});

test('builds empty snapshot when no commits in window', function () {
    $dir = createTempGitRepo();
    addCommit($dir, 'init.txt', 'hello', 'init', '2024-01-10T10:00:00+00:00');

    $reader = new GitRepoReader();
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-15T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-16T00:00:00+00:00'),
        label: '2024-01-15',
    );

    $snapshot = $reader->buildSnapshot($dir, $window);

    expect($snapshot->commitsCount)->toBe(0)
        ->and($snapshot->churn)->toBe(0)
        ->and($snapshot->filesTouched)->toBe([]);

    cleanupTempRepo($dir);
});
