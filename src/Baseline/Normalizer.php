<?php

declare(strict_types=1);

namespace Codemetry\Core\Baseline;

use Codemetry\Core\Domain\NormalizedFeatureSet;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;

final class Normalizer
{
    public function normalize(SignalSet $signals, Baseline $baseline): NormalizedFeatureSet
    {
        $normalized = [];

        foreach ($signals->signals as $key => $signal) {
            if ($signal->type !== SignalType::Numeric) {
                continue;
            }

            $dist = $baseline->get($key);
            if ($dist === null) {
                continue;
            }

            $value = (float) $signal->value;
            $normalized["norm.{$key}.z"] = $dist->zScore($value);
            $normalized["norm.{$key}.pctl"] = $dist->percentileRank($value);
        }

        return new NormalizedFeatureSet($signals, $normalized);
    }
}
