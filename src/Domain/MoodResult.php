<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

use Codemetry\Core\Ai\MoodAiSummary;

final readonly class MoodResult implements \JsonSerializable
{
    /**
     * @param array<ReasonItem> $reasons
     * @param array<string> $confounders
     * @param array<string, float> $normalized
     */
    public function __construct(
        public string $windowLabel,
        public MoodLabel $moodLabel,
        public int $moodScore,
        public float $confidence,
        public array $reasons = [],
        public array $confounders = [],
        public ?SignalSet $rawSignals = null,
        public array $normalized = [],
        public ?MoodAiSummary $aiSummary = null,
    ) {}

    /**
     * Create a new instance with AI summary applied.
     */
    public function withAiSummary(MoodAiSummary $summary): self
    {
        $newScore = max(0, min(100, $this->moodScore + $summary->scoreDelta));
        $newConfidence = max(0.0, min(1.0, $this->confidence + $summary->confidenceDelta));
        $newLabel = $summary->labelOverride ?? MoodLabel::fromScore($newScore);

        return new self(
            windowLabel: $this->windowLabel,
            moodLabel: $newLabel,
            moodScore: $newScore,
            confidence: $newConfidence,
            reasons: $this->reasons,
            confounders: $this->confounders,
            rawSignals: $this->rawSignals,
            normalized: $this->normalized,
            aiSummary: $summary,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'window_label' => $this->windowLabel,
            'mood_label' => $this->moodLabel->value,
            'mood_score' => $this->moodScore,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'confounders' => $this->confounders,
            'raw_signals' => $this->rawSignals,
            'normalized' => $this->normalized,
        ];

        if ($this->aiSummary !== null) {
            $data['ai_summary'] = $this->aiSummary;
        }

        return $data;
    }
}
