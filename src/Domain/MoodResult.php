<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

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
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'window_label' => $this->windowLabel,
            'mood_label' => $this->moodLabel->value,
            'mood_score' => $this->moodScore,
            'confidence' => $this->confidence,
            'reasons' => $this->reasons,
            'confounders' => $this->confounders,
            'raw_signals' => $this->rawSignals,
            'normalized' => $this->normalized,
        ];
    }
}
