<?php

declare(strict_types=1);

namespace Codemetry\Core\Signals\Providers;

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;
use Codemetry\Core\Signals\ProviderContext;
use Codemetry\Core\Signals\SignalProvider;

final class FollowUpFixProvider implements SignalProvider
{
    private const FIX_PATTERN = '/\b(fix|bug|hotfix|patch|typo|oops)\b/i';
    private const DEFAULT_HORIZON_DAYS = 3;

    public function id(): string
    {
        return 'follow_up_fix';
    }

    public function provide(RepoSnapshot $snapshot, ProviderContext $ctx): SignalSet
    {
        $horizonDays = $ctx->config['follow_up_horizon_days'] ?? self::DEFAULT_HORIZON_DAYS;
        $fixPattern = $ctx->config['keywords']['fix_pattern'] ?? self::FIX_PATTERN;

        if ($ctx->gitReader === null || $snapshot->filesTouchedCount === 0) {
            return $this->emptySignals($snapshot->window->label, $horizonDays);
        }

        $horizonWindow = new AnalysisWindow(
            start: $snapshot->window->end,
            end: $snapshot->window->end->modify("+{$horizonDays} days"),
            label: $snapshot->window->label . '+horizon',
        );

        $horizonCommits = $ctx->gitReader->getCommits($ctx->repoPath, $horizonWindow);

        $windowFiles = array_flip($snapshot->filesTouched);
        $touchingCount = 0;
        $fixCount = 0;

        foreach ($horizonCommits as $commit) {
            $touchesWindowFile = false;
            foreach ($commit->files as $file) {
                if (isset($windowFiles[$file])) {
                    $touchesWindowFile = true;
                    break;
                }
            }

            if ($touchesWindowFile) {
                $touchingCount++;
                if (preg_match($fixPattern, $commit->subject)) {
                    $fixCount++;
                }
            }
        }

        $fixDensity = $fixCount / max(1, $snapshot->churn);

        return new SignalSet($snapshot->window->label, [
            'followup.horizon_days' => new Signal(
                'followup.horizon_days',
                SignalType::Numeric,
                $horizonDays,
                'Number of days scanned after window',
            ),
            'followup.touching_commits' => new Signal(
                'followup.touching_commits',
                SignalType::Numeric,
                $touchingCount,
                'Horizon commits touching files from window',
            ),
            'followup.fix_commits' => new Signal(
                'followup.fix_commits',
                SignalType::Numeric,
                $fixCount,
                'Horizon commits with fix keywords touching window files',
            ),
            'followup.fix_density' => new Signal(
                'followup.fix_density',
                SignalType::Numeric,
                round($fixDensity, 6),
                'Fix commits / max(1, churn)',
            ),
        ]);
    }

    private function emptySignals(string $windowLabel, int $horizonDays): SignalSet
    {
        return new SignalSet($windowLabel, [
            'followup.horizon_days' => new Signal(
                'followup.horizon_days',
                SignalType::Numeric,
                $horizonDays,
                'Number of days scanned after window',
            ),
            'followup.touching_commits' => new Signal(
                'followup.touching_commits',
                SignalType::Numeric,
                0,
                'Horizon commits touching files from window',
            ),
            'followup.fix_commits' => new Signal(
                'followup.fix_commits',
                SignalType::Numeric,
                0,
                'Horizon commits with fix keywords touching window files',
            ),
            'followup.fix_density' => new Signal(
                'followup.fix_density',
                SignalType::Numeric,
                0.0,
                'Fix commits / max(1, churn)',
            ),
        ]);
    }
}
