<?php

declare(strict_types=1);

namespace Codemetry\Core\Baseline;

final readonly class BaselineDistribution
{
    /**
     * @param array<float> $sortedValues
     */
    public function __construct(
        public float $mean,
        public float $stddev,
        public array $sortedValues,
    ) {}

    /**
     * @param array<int|float> $values
     */
    public static function fromValues(array $values): self
    {
        if ($values === []) {
            return new self(0.0, 0.0, []);
        }

        $count = count($values);
        $floatValues = array_map(fn($v) => (float) $v, $values);
        $mean = array_sum($floatValues) / $count;

        $variance = 0.0;
        foreach ($floatValues as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $stddev = $count > 1 ? sqrt($variance / ($count - 1)) : 0.0;

        sort($floatValues);

        return new self(
            mean: round($mean, 6),
            stddev: round($stddev, 6),
            sortedValues: $floatValues,
        );
    }

    public function zScore(float $value): float
    {
        if ($this->stddev === 0.0) {
            return 0.0;
        }

        return round(($value - $this->mean) / $this->stddev, 4);
    }

    public function percentileRank(float $value): float
    {
        if ($this->sortedValues === []) {
            return 50.0;
        }

        $count = count($this->sortedValues);
        $belowOrEqual = 0;

        foreach ($this->sortedValues as $v) {
            if ($v <= $value) {
                $belowOrEqual++;
            } else {
                break;
            }
        }

        return round(($belowOrEqual / $count) * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mean' => $this->mean,
            'stddev' => $this->stddev,
            'sorted_values' => $this->sortedValues,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mean: (float) $data['mean'],
            stddev: (float) $data['stddev'],
            sortedValues: array_map(fn($v) => (float) $v, $data['sorted_values']),
        );
    }
}
