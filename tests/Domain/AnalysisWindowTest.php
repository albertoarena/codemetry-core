<?php

use Codemetry\Core\Domain\AnalysisWindow;

test('computes duration in seconds', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-02T00:00:00+00:00'),
        label: '2024-01-01',
    );

    expect($window->durationSeconds())->toBe(86400);
});

test('serializes to JSON', function () {
    $window = new AnalysisWindow(
        start: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        end: new DateTimeImmutable('2024-01-01T23:59:59+00:00'),
        label: '2024-01-01',
    );

    $json = json_decode(json_encode($window), true);

    expect($json['label'])->toBe('2024-01-01')
        ->and($json['start'])->toBe('2024-01-01T00:00:00+00:00')
        ->and($json['end'])->toBe('2024-01-01T23:59:59+00:00')
        ->and($json['duration_seconds'])->toBe(86399);
});
