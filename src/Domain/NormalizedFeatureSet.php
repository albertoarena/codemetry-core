<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class NormalizedFeatureSet implements \JsonSerializable
{
    /**
     * @param array<string, float> $normalized
     */
    public function __construct(
        public SignalSet $rawSignals,
        public array $normalized = [],
    ) {}

    public function zScore(string $key): ?float
    {
        return $this->normalized["norm.{$key}.z"] ?? null;
    }

    public function percentile(string $key): ?float
    {
        return $this->normalized["norm.{$key}.pctl"] ?? null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'raw_signals' => $this->rawSignals,
            'normalized' => $this->normalized,
        ];
    }
}
