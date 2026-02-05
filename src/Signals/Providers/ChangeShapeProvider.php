<?php

declare(strict_types=1);

namespace Codemetry\Core\Signals\Providers;

use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;
use Codemetry\Core\Signals\ProviderContext;
use Codemetry\Core\Signals\SignalProvider;

final class ChangeShapeProvider implements SignalProvider
{
    public function id(): string
    {
        return 'change_shape';
    }

    public function provide(RepoSnapshot $snapshot, ProviderContext $ctx): SignalSet
    {
        $commitsCount = $snapshot->commitsCount;
        $churnPerCommit = $commitsCount > 0
            ? round($snapshot->churn / $commitsCount, 2)
            : 0.0;

        $scatter = $this->computeScatter($snapshot);

        return new SignalSet($snapshot->window->label, [
            'change.added' => new Signal(
                'change.added',
                SignalType::Numeric,
                $snapshot->added,
                'Total lines added',
            ),
            'change.deleted' => new Signal(
                'change.deleted',
                SignalType::Numeric,
                $snapshot->deleted,
                'Total lines deleted',
            ),
            'change.churn' => new Signal(
                'change.churn',
                SignalType::Numeric,
                $snapshot->churn,
                'Total lines added + deleted',
            ),
            'change.commits_count' => new Signal(
                'change.commits_count',
                SignalType::Numeric,
                $commitsCount,
                'Number of commits in window',
            ),
            'change.files_touched' => new Signal(
                'change.files_touched',
                SignalType::Numeric,
                $snapshot->filesTouchedCount,
                'Number of unique files touched',
            ),
            'change.churn_per_commit' => new Signal(
                'change.churn_per_commit',
                SignalType::Numeric,
                $churnPerCommit,
                'Average churn per commit',
            ),
            'change.scatter' => new Signal(
                'change.scatter',
                SignalType::Numeric,
                $scatter,
                'Number of unique directories touched',
            ),
        ]);
    }

    private function computeScatter(RepoSnapshot $snapshot): int
    {
        $dirs = [];
        foreach ($snapshot->filesTouched as $file) {
            $dir = dirname($file);
            $dirs[$dir] = true;
        }

        return count($dirs);
    }
}
