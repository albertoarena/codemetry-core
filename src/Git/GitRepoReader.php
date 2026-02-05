<?php

declare(strict_types=1);

namespace Codemetry\Core\Git;

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\CommitInfo;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Exception\GitCommandException;
use Codemetry\Core\Exception\InvalidRepoException;
use Symfony\Component\Process\Process;

final class GitRepoReader
{
    public function validateRepo(string $repoPath): void
    {
        if (!is_dir($repoPath)) {
            throw InvalidRepoException::pathNotFound($repoPath);
        }

        $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $repoPath);
        $process->run();

        if (!$process->isSuccessful() || trim($process->getOutput()) !== 'true') {
            throw InvalidRepoException::notAGitRepo($repoPath);
        }
    }

    /**
     * @return array<CommitInfo>
     */
    public function getCommits(
        string $repoPath,
        AnalysisWindow $window,
        ?string $author = null,
        ?string $branch = null,
    ): array {
        $args = ['git', 'log'];

        if ($branch !== null) {
            $args[] = $branch;
        }

        $args[] = '--since=' . $window->start->format('c');
        $args[] = '--until=' . $window->end->format('c');
        $args[] = '--pretty=format:%H%x09%an%x09%ae%x09%ad%x09%s';
        $args[] = '--date=iso-strict';

        if ($author !== null) {
            $args[] = '--author=' . $author;
        }

        $output = $this->runGit($args, $repoPath);

        if ($output === '') {
            return [];
        }

        $commits = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 5);
            if (count($parts) < 5) {
                continue;
            }

            [$hash, $authorName, $authorEmail, $authoredAt, $subject] = $parts;

            $numstat = $this->getNumstat($repoPath, $hash);

            $commits[] = new CommitInfo(
                hash: $hash,
                authorName: $authorName,
                authorEmail: $authorEmail,
                authoredAt: new \DateTimeImmutable($authoredAt),
                subject: $subject,
                insertions: $numstat['insertions'],
                deletions: $numstat['deletions'],
                files: $numstat['files'],
            );
        }

        return $commits;
    }

    /**
     * @return array{insertions: int, deletions: int, files: array<string>}
     */
    public function getNumstat(string $repoPath, string $hash): array
    {
        $output = $this->runGit(
            ['git', 'show', '--numstat', '--pretty=format:', $hash],
            $repoPath,
        );

        $insertions = 0;
        $deletions = 0;
        $files = [];

        if ($output === '') {
            return compact('insertions', 'deletions', 'files');
        }

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t/', $line, 3);
            if ($parts === false || count($parts) < 3) {
                continue;
            }

            [$added, $deleted, $path] = $parts;

            // Binary files show '-' for added/deleted
            if ($added !== '-') {
                $insertions += (int) $added;
            }
            if ($deleted !== '-') {
                $deletions += (int) $deleted;
            }

            $files[] = $path;
        }

        return compact('insertions', 'deletions', 'files');
    }

    public function buildSnapshot(
        string $repoPath,
        AnalysisWindow $window,
        ?string $author = null,
        ?string $branch = null,
    ): RepoSnapshot {
        $commits = $this->getCommits($repoPath, $window, $author, $branch);

        return RepoSnapshot::fromCommits($window, $commits);
    }

    private function runGit(array $args, string $cwd): string
    {
        $process = new Process($args, $cwd);
        $process->run();

        if (!$process->isSuccessful()) {
            throw GitCommandException::failed(
                implode(' ', $args),
                $process->getErrorOutput(),
            );
        }

        return trim($process->getOutput());
    }
}
