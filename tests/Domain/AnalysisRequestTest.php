<?php

use Codemetry\Core\Domain\AnalysisRequest;

test('has sensible defaults', function () {
    $request = new AnalysisRequest();

    expect($request->since)->toBeNull()
        ->and($request->until)->toBeNull()
        ->and($request->days)->toBeNull()
        ->and($request->author)->toBeNull()
        ->and($request->branch)->toBeNull()
        ->and($request->timezone)->toBeNull()
        ->and($request->baselineDays)->toBe(56)
        ->and($request->followUpHorizonDays)->toBe(3)
        ->and($request->aiEnabled)->toBeFalse()
        ->and($request->aiEngine)->toBe('openai')
        ->and($request->outputFormat)->toBe('json');
});

test('accepts all parameters', function () {
    $since = new DateTimeImmutable('2024-01-01');
    $until = new DateTimeImmutable('2024-01-07');
    $tz = new DateTimeZone('Europe/London');

    $request = new AnalysisRequest(
        since: $since,
        until: $until,
        days: 7,
        author: 'alice',
        branch: 'main',
        timezone: $tz,
        baselineDays: 30,
        followUpHorizonDays: 5,
        aiEnabled: true,
        aiEngine: 'anthropic',
        outputFormat: 'table',
    );

    expect($request->since)->toBe($since)
        ->and($request->days)->toBe(7)
        ->and($request->author)->toBe('alice')
        ->and($request->baselineDays)->toBe(30)
        ->and($request->aiEnabled)->toBeTrue()
        ->and($request->aiEngine)->toBe('anthropic')
        ->and($request->outputFormat)->toBe('table');
});

test('toSummary returns array representation', function () {
    $request = new AnalysisRequest(days: 7, author: 'bob');
    $summary = $request->toSummary();

    expect($summary)->toBeArray()
        ->and($summary['days'])->toBe(7)
        ->and($summary['author'])->toBe('bob')
        ->and($summary['since'])->toBeNull()
        ->and($summary['baseline_days'])->toBe(56)
        ->and($summary['ai_enabled'])->toBeFalse();
});
