<?php

declare(strict_types=1);

namespace Codemetry\Core\Baseline;

final readonly class Baseline
{
    /**
     * @param array<string, BaselineDistribution> $distributions
     */
    public function __construct(
        public array $distributions,
        public int $windowCount,
    ) {}

    public function get(string $key): ?BaselineDistribution
    {
        return $this->distributions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->distributions[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $dists = [];
        foreach ($this->distributions as $key => $dist) {
            $dists[$key] = $dist->toArray();
        }

        return [
            'distributions' => $dists,
            'window_count' => $this->windowCount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $distributions = [];
        foreach ($data['distributions'] as $key => $distData) {
            $distributions[$key] = BaselineDistribution::fromArray($distData);
        }

        return new self(
            distributions: $distributions,
            windowCount: (int) $data['window_count'],
        );
    }
}
