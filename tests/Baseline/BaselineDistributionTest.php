<?php

use Codemetry\Core\Baseline\BaselineDistribution;

test('computes mean and stddev from values', function () {
    $dist = BaselineDistribution::fromValues([10, 20, 30, 40, 50]);

    expect($dist->mean)->toBe(30.0)
        ->and($dist->stddev)->toBe(round(sqrt(250), 6))
        ->and($dist->sortedValues)->toBe([10.0, 20.0, 30.0, 40.0, 50.0]);
});

test('handles single value', function () {
    $dist = BaselineDistribution::fromValues([42]);

    expect($dist->mean)->toBe(42.0)
        ->and($dist->stddev)->toBe(0.0);
});

test('handles empty values', function () {
    $dist = BaselineDistribution::fromValues([]);

    expect($dist->mean)->toBe(0.0)
        ->and($dist->stddev)->toBe(0.0)
        ->and($dist->sortedValues)->toBe([]);
});

test('computes z-score', function () {
    // mean=30, stddev~15.811
    $dist = BaselineDistribution::fromValues([10, 20, 30, 40, 50]);

    // z = (50 - 30) / 15.811 = 1.2649
    expect($dist->zScore(50))->toBe(round(20 / sqrt(250), 4))
        ->and($dist->zScore(30))->toBe(0.0) // at mean
        ->and($dist->zScore(10))->toBe(round(-20 / sqrt(250), 4)); // below mean
});

test('z-score returns 0 when stddev is 0', function () {
    $dist = BaselineDistribution::fromValues([5, 5, 5]);

    expect($dist->zScore(5))->toBe(0.0)
        ->and($dist->zScore(100))->toBe(0.0);
});

test('computes percentile rank', function () {
    $dist = BaselineDistribution::fromValues([10, 20, 30, 40, 50]);

    // 10 is at position 1/5 = 20th percentile
    expect($dist->percentileRank(10))->toBe(20.0)
        // 30 is at position 3/5 = 60th percentile
        ->and($dist->percentileRank(30))->toBe(60.0)
        // 50 is at position 5/5 = 100th percentile
        ->and($dist->percentileRank(50))->toBe(100.0)
        // 5 is below all = 0th percentile
        ->and($dist->percentileRank(5))->toBe(0.0);
});

test('percentile rank returns 50 for empty distribution', function () {
    $dist = BaselineDistribution::fromValues([]);

    expect($dist->percentileRank(42))->toBe(50.0);
});

test('serializes to and from array', function () {
    $original = BaselineDistribution::fromValues([5, 10, 15, 20, 25]);
    $array = $original->toArray();
    $restored = BaselineDistribution::fromArray($array);

    expect($restored->mean)->toBe($original->mean)
        ->and($restored->stddev)->toBe($original->stddev)
        ->and($restored->sortedValues)->toBe($original->sortedValues);
});
