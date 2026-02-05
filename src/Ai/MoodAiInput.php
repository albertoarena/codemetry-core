<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai;

use Codemetry\Core\Domain\MoodLabel;
use Codemetry\Core\Domain\ReasonItem;

/**
 * Metrics-only input for AI engines. Never contains raw code or diffs.
 */
final readonly class MoodAiInput implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $rawSignals
     * @param array<string, float> $normalized
     * @param array<ReasonItem> $reasons
     * @param array<string> $confounders
     * @param array<string, int> $extensionHistogram
     * @param array<string> $topPaths
     */
    public function __construct(
        public string $windowLabel,
        public MoodLabel $moodLabel,
        public int $moodScore,
        public float $confidence,
        public array $rawSignals,
        public array $normalized,
        public array $reasons,
        public array $confounders,
        public int $commitsCount,
        public array $extensionHistogram = [],
        public array $topPaths = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'window_label' => $this->windowLabel,
            'mood_label' => $this->moodLabel->value,
            'mood_score' => $this->moodScore,
            'confidence' => $this->confidence,
            'raw_signals' => $this->rawSignals,
            'normalized' => $this->normalized,
            'reasons' => array_map(fn(ReasonItem $r) => $r->jsonSerialize(), $this->reasons),
            'confounders' => $this->confounders,
            'commits_count' => $this->commitsCount,
            'extension_histogram' => $this->extensionHistogram,
            'top_paths' => $this->topPaths,
        ];
    }
}
