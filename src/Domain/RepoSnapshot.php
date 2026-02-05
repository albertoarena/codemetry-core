<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class RepoSnapshot implements \JsonSerializable
{
    /**
     * @param array<CommitInfo> $commits
     * @param array<string> $filesTouched
     */
    public function __construct(
        public AnalysisWindow $window,
        public array $commits,
        public array $filesTouched,
        public int $commitsCount,
        public int $filesTouchedCount,
        public int $added,
        public int $deleted,
        public int $churn,
    ) {}

    /**
     * @param array<CommitInfo> $commits
     */
    public static function fromCommits(AnalysisWindow $window, array $commits): self
    {
        $filesTouched = [];
        $added = 0;
        $deleted = 0;

        foreach ($commits as $commit) {
            $added += $commit->insertions;
            $deleted += $commit->deletions;
            foreach ($commit->files as $file) {
                $filesTouched[$file] = true;
            }
        }

        $uniqueFiles = array_keys($filesTouched);

        return new self(
            window: $window,
            commits: $commits,
            filesTouched: $uniqueFiles,
            commitsCount: count($commits),
            filesTouchedCount: count($uniqueFiles),
            added: $added,
            deleted: $deleted,
            churn: $added + $deleted,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'window' => $this->window,
            'commits' => $this->commits,
            'files_touched' => $this->filesTouched,
            'totals' => [
                'commits_count' => $this->commitsCount,
                'files_touched_count' => $this->filesTouchedCount,
                'added' => $this->added,
                'deleted' => $this->deleted,
                'churn' => $this->churn,
            ],
        ];
    }
}
