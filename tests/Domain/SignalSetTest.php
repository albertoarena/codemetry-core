<?php

use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;

test('signal set can be created with signals', function () {
    $signals = [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 100),
        'change.scatter' => new Signal('change.scatter', SignalType::Numeric, 5),
    ];

    $set = new SignalSet('2024-01-15', $signals);

    expect($set->windowLabel)->toBe('2024-01-15')
        ->and($set->signals)->toBe($signals);
});

test('signal set get returns signal when exists', function () {
    $signal = new Signal('change.churn', SignalType::Numeric, 100);
    $set = new SignalSet('2024-01-15', ['change.churn' => $signal]);

    expect($set->get('change.churn'))->toBe($signal);
});

test('signal set get returns null when signal does not exist', function () {
    $set = new SignalSet('2024-01-15', []);

    expect($set->get('change.churn'))->toBeNull();
});

test('signal set has returns true when signal exists', function () {
    $signal = new Signal('change.churn', SignalType::Numeric, 100);
    $set = new SignalSet('2024-01-15', ['change.churn' => $signal]);

    expect($set->has('change.churn'))->toBeTrue();
});

test('signal set has returns false when signal does not exist', function () {
    $set = new SignalSet('2024-01-15', []);

    expect($set->has('change.churn'))->toBeFalse();
});

test('signal set all returns all signals', function () {
    $signals = [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 100),
        'change.scatter' => new Signal('change.scatter', SignalType::Numeric, 5),
        'change.commits_count' => new Signal('change.commits_count', SignalType::Numeric, 3),
    ];

    $set = new SignalSet('2024-01-15', $signals);

    $all = $set->all();

    expect($all)->toBe($signals)
        ->and($all)->toHaveCount(3)
        ->and($all['change.churn']->value)->toBe(100)
        ->and($all['change.scatter']->value)->toBe(5)
        ->and($all['change.commits_count']->value)->toBe(3);
});

test('signal set all returns empty array when no signals', function () {
    $set = new SignalSet('2024-01-15', []);

    expect($set->all())->toBe([])
        ->and($set->all())->toHaveCount(0);
});

test('signal set merge combines signals from two sets', function () {
    $set1 = new SignalSet('2024-01-15', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 100),
    ]);

    $set2 = new SignalSet('2024-01-15', [
        'change.scatter' => new Signal('change.scatter', SignalType::Numeric, 5),
    ]);

    $merged = $set1->merge($set2);

    expect($merged->all())->toHaveCount(2)
        ->and($merged->has('change.churn'))->toBeTrue()
        ->and($merged->has('change.scatter'))->toBeTrue();
});

test('signal set serializes to JSON correctly', function () {
    $signals = [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 100),
    ];

    $set = new SignalSet('2024-01-15', $signals);
    $json = json_encode($set, JSON_PRETTY_PRINT);

    $decoded = json_decode($json, true);

    expect($decoded['window_label'])->toBe('2024-01-15')
        ->and($decoded['signals'])->toHaveKey('change.churn')
        ->and($decoded['signals']['change.churn']['value'])->toBe(100);
});
