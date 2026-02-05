<?php

use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;

test('signal stores key, type, and value', function () {
    $signal = new Signal(
        key: 'change.churn',
        type: SignalType::Numeric,
        value: 42,
        description: 'Total lines added + deleted',
    );

    expect($signal->key)->toBe('change.churn')
        ->and($signal->type)->toBe(SignalType::Numeric)
        ->and($signal->value)->toBe(42)
        ->and($signal->description)->toBe('Total lines added + deleted');
});

test('signal serializes to JSON', function () {
    $signal = new Signal(
        key: 'msg.fix_ratio',
        type: SignalType::Numeric,
        value: 0.25,
    );

    $json = json_decode(json_encode($signal), true);

    expect($json['key'])->toBe('msg.fix_ratio')
        ->and($json['type'])->toBe('numeric')
        ->and($json['value'])->toBe(0.25);
});

test('signal set provides get/has/merge', function () {
    $s1 = new Signal('a', SignalType::Numeric, 1);
    $s2 = new Signal('b', SignalType::Numeric, 2);
    $s3 = new Signal('c', SignalType::Boolean, true);

    $set1 = new SignalSet('2024-01-01', ['a' => $s1, 'b' => $s2]);
    $set2 = new SignalSet('2024-01-01', ['c' => $s3]);

    expect($set1->has('a'))->toBeTrue()
        ->and($set1->has('z'))->toBeFalse()
        ->and($set1->get('a'))->toBe($s1)
        ->and($set1->get('z'))->toBeNull();

    $merged = $set1->merge($set2);

    expect($merged->has('a'))->toBeTrue()
        ->and($merged->has('c'))->toBeTrue()
        ->and(count($merged->signals))->toBe(3);
});

test('signal set serializes to JSON', function () {
    $set = new SignalSet('2024-01-01', [
        'x' => new Signal('x', SignalType::Numeric, 99),
    ]);

    $json = json_decode(json_encode($set), true);

    expect($json['window_label'])->toBe('2024-01-01')
        ->and($json['signals']['x']['value'])->toBe(99);
});
