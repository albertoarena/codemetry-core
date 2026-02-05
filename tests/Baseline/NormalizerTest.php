<?php

use Codemetry\Core\Baseline\Baseline;
use Codemetry\Core\Baseline\BaselineDistribution;
use Codemetry\Core\Baseline\Normalizer;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;

test('normalizes numeric signals against baseline', function () {
    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10, 20, 30, 40, 50]),
        'change.commits_count' => BaselineDistribution::fromValues([1, 2, 3, 4, 5]),
    ], 5);

    $signals = new SignalSet('2024-01-15', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 50),
        'change.commits_count' => new Signal('change.commits_count', SignalType::Numeric, 3),
    ]);

    $normalizer = new Normalizer();
    $result = $normalizer->normalize($signals, $baseline);

    // churn=50 is at 100th percentile
    expect($result->percentile('change.churn'))->toBe(100.0)
        // commits=3 is at 60th percentile
        ->and($result->percentile('change.commits_count'))->toBe(60.0)
        // z-scores should be non-null
        ->and($result->zScore('change.churn'))->not->toBeNull()
        ->and($result->zScore('change.commits_count'))->not->toBeNull();
});

test('skips non-numeric signals', function () {
    $baseline = new Baseline([
        'some.bool' => BaselineDistribution::fromValues([1, 0, 1]),
    ], 3);

    $signals = new SignalSet('2024-01-15', [
        'some.bool' => new Signal('some.bool', SignalType::Boolean, true),
    ]);

    $normalizer = new Normalizer();
    $result = $normalizer->normalize($signals, $baseline);

    expect($result->zScore('some.bool'))->toBeNull()
        ->and($result->percentile('some.bool'))->toBeNull();
});

test('skips signals without baseline distribution', function () {
    $baseline = new Baseline([], 0);

    $signals = new SignalSet('2024-01-15', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 50),
    ]);

    $normalizer = new Normalizer();
    $result = $normalizer->normalize($signals, $baseline);

    expect($result->zScore('change.churn'))->toBeNull()
        ->and($result->percentile('change.churn'))->toBeNull();
});

test('preserves raw signals in result', function () {
    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10, 20, 30]),
    ], 3);

    $signals = new SignalSet('2024-01-15', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 25),
    ]);

    $normalizer = new Normalizer();
    $result = $normalizer->normalize($signals, $baseline);

    expect($result->rawSignals)->toBe($signals)
        ->and($result->rawSignals->get('change.churn')->value)->toBe(25);
});

test('normalized keys follow naming convention', function () {
    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10, 20, 30]),
    ], 3);

    $signals = new SignalSet('2024-01-15', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 20),
    ]);

    $normalizer = new Normalizer();
    $result = $normalizer->normalize($signals, $baseline);

    expect($result->normalized)->toHaveKey('norm.change.churn.z')
        ->and($result->normalized)->toHaveKey('norm.change.churn.pctl');
});
