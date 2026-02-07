<?php

declare(strict_types=1);

namespace Codemetry\Core\Scoring;

use Codemetry\Core\Domain\Confounder;
use Codemetry\Core\Domain\Direction;
use Codemetry\Core\Domain\MoodLabel;
use Codemetry\Core\Domain\MoodResult;
use Codemetry\Core\Domain\NormalizedFeatureSet;
use Codemetry\Core\Domain\ReasonItem;

final class HeuristicScorer
{
    private const BASE_SCORE = 70;

    /**
     * @param array<string> $confounders Pre-existing confounders (e.g. from provider registry)
     */
    public function score(NormalizedFeatureSet $features, array $confounders = []): MoodResult
    {
        $score = self::BASE_SCORE;
        $reasons = [];

        // --- Penalties ---

        $churnPctl = $features->percentile('change.churn');
        if ($churnPctl !== null) {
            if ($churnPctl >= 95) {
                $score -= 20;
                $reasons[] = new ReasonItem('change.churn', Direction::Negative, 20.0, 'Churn at p95+');
            } elseif ($churnPctl >= 90) {
                $score -= 12;
                $reasons[] = new ReasonItem('change.churn', Direction::Negative, 12.0, 'Churn at p90-p95');
            }
        }

        $scatterPctl = $features->percentile('change.scatter');
        if ($scatterPctl !== null && $scatterPctl >= 90) {
            $score -= 10;
            $reasons[] = new ReasonItem('change.scatter', Direction::Negative, 10.0, 'High scatter at p90+');
        }

        $fixDensityPctl = $features->percentile('followup.fix_density');
        if ($fixDensityPctl !== null) {
            if ($fixDensityPctl >= 95) {
                $score -= 25;
                $reasons[] = new ReasonItem('followup.fix_density', Direction::Negative, 25.0, 'Follow-up fix density at p95+');
            } elseif ($fixDensityPctl >= 90) {
                $score -= 15;
                $reasons[] = new ReasonItem('followup.fix_density', Direction::Negative, 15.0, 'Follow-up fix density at p90-p95');
            }
        }

        $revertCount = $this->rawValue($features, 'msg.revert_count');
        if ($revertCount !== null && $revertCount > 0) {
            $score -= 15;
            $reasons[] = new ReasonItem('msg.revert_count', Direction::Negative, 15.0, 'Reverts detected');
        }

        $wipCount = $this->rawValue($features, 'msg.wip_count');
        $commitsCount = $this->rawValue($features, 'change.commits_count');
        if ($wipCount !== null && $commitsCount !== null && $commitsCount > 0) {
            $wipRatio = $wipCount / $commitsCount;
            if ($wipRatio >= 0.3) {
                $score -= 8;
                $reasons[] = new ReasonItem('msg.wip_count', Direction::Negative, 8.0, 'High WIP ratio (>= 0.3)');
            }
        }

        // --- Rewards ---

        if ($churnPctl !== null && $churnPctl <= 25) {
            $fixDensityPctlForReward = $fixDensityPctl ?? $features->percentile('followup.fix_density');
            if ($fixDensityPctlForReward !== null && $fixDensityPctlForReward <= 25) {
                $score += 5;
                $reasons[] = new ReasonItem('change.churn', Direction::Positive, 5.0, 'Low churn and low fix density');
            }
        }

        // --- Clamp ---

        $score = max(0, min(100, $score));

        // --- Label ---

        $moodLabel = MoodLabel::fromScore($score);

        // --- Confidence ---

        $confidence = $this->computeConfidence($features, $confounders);

        // --- Confounders ---

        $confounders = $this->detectConfounders($features, $confounders);

        // --- Sort reasons by magnitude, keep top 6 ---

        usort($reasons, fn(ReasonItem $a, ReasonItem $b) => $b->magnitude <=> $a->magnitude);
        $reasons = array_slice($reasons, 0, 6);

        return new MoodResult(
            windowLabel: $features->rawSignals->windowLabel,
            moodLabel: $moodLabel,
            moodScore: $score,
            confidence: $confidence,
            reasons: $reasons,
            confounders: $confounders,
            rawSignals: $features->rawSignals,
            normalized: $features->normalized,
        );
    }

    /**
     * @param array<string> $confounders
     */
    private function computeConfidence(NormalizedFeatureSet $features, array $confounders): float
    {
        $confidence = 0.6;

        $commitsCount = $this->rawValue($features, 'change.commits_count');

        if ($commitsCount !== null && $commitsCount >= 3) {
            $confidence += 0.1;
        }

        if ($features->percentile('followup.fix_density') !== null) {
            $confidence += 0.1;
        }

        if ($commitsCount !== null && $commitsCount <= 1) {
            $confidence -= 0.2;
        }

        $keyProviders = ['change_shape', 'follow_up_fix', 'commit_message'];
        foreach ($keyProviders as $providerId) {
            if (in_array(Confounder::providerSkipped($providerId), $confounders, true)) {
                $confidence -= 0.1;
            }
        }

        return round(max(0.0, min(1.0, $confidence)), 2);
    }

    /**
     * @param array<string> $existing
     * @return array<string>
     */
    private function detectConfounders(NormalizedFeatureSet $features, array $existing): array
    {
        $confounders = $existing;

        $churnPctl = $features->percentile('change.churn');
        $fixDensityPctl = $features->percentile('followup.fix_density');

        // Large refactor suspected: high churn but low follow-up fix density
        if ($churnPctl !== null && $churnPctl >= 95
            && $fixDensityPctl !== null && $fixDensityPctl <= 50) {
            $confounders[] = Confounder::LARGE_REFACTOR_SUSPECTED;
        }

        // Formatting/rename suspected: very high churn + many files + low follow-up
        $filesTouchedPctl = $features->percentile('change.files_touched');
        if ($churnPctl !== null && $churnPctl >= 95
            && $filesTouchedPctl !== null && $filesTouchedPctl >= 90
            && ($fixDensityPctl === null || $fixDensityPctl <= 25)) {
            $confounders[] = Confounder::FORMATTING_OR_RENAME_SUSPECTED;
        }

        return array_values(array_unique($confounders));
    }

    private function rawValue(NormalizedFeatureSet $features, string $key): int|float|null
    {
        $signal = $features->rawSignals->get($key);
        if ($signal === null) {
            return null;
        }

        return is_numeric($signal->value) ? $signal->value : null;
    }
}
