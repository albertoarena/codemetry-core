<?php

use Codemetry\Core\Domain\NormalizedFeatureSet;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;

test('provides z-score and percentile accessors', function () {
    $raw = new SignalSet('2024-01-01', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 100),
    ]);

    $features = new NormalizedFeatureSet($raw, [
        'norm.change.churn.z' => 1.5,
        'norm.change.churn.pctl' => 93.0,
    ]);

    expect($features->zScore('change.churn'))->toBe(1.5)
        ->and($features->percentile('change.churn'))->toBe(93.0)
        ->and($features->zScore('nonexistent'))->toBeNull()
        ->and($features->percentile('nonexistent'))->toBeNull();
});

test('serializes to JSON', function () {
    $raw = new SignalSet('2024-01-01');
    $features = new NormalizedFeatureSet($raw, ['norm.x.z' => 0.5]);

    $json = json_decode(json_encode($features), true);

    expect($json['raw_signals']['window_label'])->toBe('2024-01-01')
        ->and($json['normalized']['norm.x.z'])->toBe(0.5);
});
