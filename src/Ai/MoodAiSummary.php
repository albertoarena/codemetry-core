<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai;

use Codemetry\Core\Domain\MoodLabel;

/**
 * AI-generated summary with optional bounded adjustments.
 */
final readonly class MoodAiSummary implements \JsonSerializable
{
    /**
     * @param array<string> $explanationBullets
     * @param int $scoreDelta Bounded to [-10, +10]
     * @param float $confidenceDelta Bounded to [-0.1, +0.1]
     */
    public function __construct(
        public array $explanationBullets = [],
        public int $scoreDelta = 0,
        public float $confidenceDelta = 0.0,
        public ?MoodLabel $labelOverride = null,
    ) {}

    /**
     * Create from AI response array with validation and bounds clamping.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $bullets = $data['explanation_bullets'] ?? $data['explanationBullets'] ?? [];
        if (!is_array($bullets)) {
            $bullets = [];
        }

        $scoreDelta = (int) ($data['score_delta'] ?? $data['scoreDelta'] ?? 0);
        $scoreDelta = max(-10, min(10, $scoreDelta));

        $confidenceDelta = (float) ($data['confidence_delta'] ?? $data['confidenceDelta'] ?? 0.0);
        $confidenceDelta = max(-0.1, min(0.1, $confidenceDelta));

        $labelOverride = null;
        if (isset($data['label_override']) || isset($data['labelOverride'])) {
            $label = $data['label_override'] ?? $data['labelOverride'];
            $labelOverride = MoodLabel::tryFrom($label);
        }

        return new self(
            explanationBullets: array_values(array_filter($bullets, 'is_string')),
            scoreDelta: $scoreDelta,
            confidenceDelta: $confidenceDelta,
            labelOverride: $labelOverride,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'explanation_bullets' => $this->explanationBullets,
            'score_delta' => $this->scoreDelta,
            'confidence_delta' => $this->confidenceDelta,
            'label_override' => $this->labelOverride?->value,
        ];
    }
}
