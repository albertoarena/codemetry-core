<?php

use Codemetry\Core\Domain\AnalysisRequest;
use Codemetry\Core\Domain\AnalysisResult;
use Codemetry\Core\Domain\Direction;
use Codemetry\Core\Domain\MoodLabel;
use Codemetry\Core\Domain\MoodResult;
use Codemetry\Core\Domain\ReasonItem;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;

test('analysis result includes schema version 1.0', function () {
    $result = new AnalysisResult(
        repoId: 'abc123',
        analyzedAt: new DateTimeImmutable('2024-01-07T12:00:00+00:00'),
        requestSummary: (new AnalysisRequest(days: 7))->toSummary(),
        windows: [],
    );

    $json = json_decode($result->toJson(), true);

    expect($json['schema_version'])->toBe('1.0')
        ->and($json['repo_id'])->toBe('abc123')
        ->and($json['analyzed_at'])->toBe('2024-01-07T12:00:00+00:00')
        ->and($json['windows'])->toBe([]);
});

test('full analysis result serializes correctly', function () {
    $request = new AnalysisRequest(days: 7, author: 'alice');

    $reason = new ReasonItem(
        signalKey: 'change.churn',
        direction: Direction::Negative,
        magnitude: 20.0,
        summary: 'Elevated churn',
    );

    $signals = new SignalSet('2024-01-01', [
        'change.churn' => new Signal('change.churn', SignalType::Numeric, 150),
        'change.commits_count' => new Signal('change.commits_count', SignalType::Numeric, 5),
    ]);

    $mood = new MoodResult(
        windowLabel: '2024-01-01',
        moodLabel: MoodLabel::Medium,
        moodScore: 55,
        confidence: 0.8,
        reasons: [$reason],
        confounders: [],
        rawSignals: $signals,
        normalized: ['norm.change.churn.z' => 1.2, 'norm.change.churn.pctl' => 88.0],
    );

    $result = new AnalysisResult(
        repoId: 'repo-hash',
        analyzedAt: new DateTimeImmutable('2024-01-07T18:00:00+00:00'),
        requestSummary: $request->toSummary(),
        windows: [$mood],
    );

    $json = json_decode($result->toJson(JSON_PRETTY_PRINT), true);

    expect($json['schema_version'])->toBe('1.0')
        ->and($json['repo_id'])->toBe('repo-hash')
        ->and($json['request_summary']['days'])->toBe(7)
        ->and($json['request_summary']['author'])->toBe('alice')
        ->and($json['windows'])->toHaveCount(1)
        ->and($json['windows'][0]['mood_label'])->toBe('medium')
        ->and($json['windows'][0]['mood_score'])->toBe(55)
        ->and($json['windows'][0]['confidence'])->toBe(0.8)
        ->and($json['windows'][0]['reasons'][0]['signal_key'])->toBe('change.churn')
        ->and($json['windows'][0]['raw_signals']['signals']['change.churn']['value'])->toBe(150)
        ->and($json['windows'][0]['normalized']['norm.change.churn.pctl'])->toEqual(88.0);
});

test('toJson produces valid JSON string', function () {
    $result = new AnalysisResult(
        repoId: 'test',
        analyzedAt: new DateTimeImmutable(),
        requestSummary: [],
        windows: [],
    );

    $jsonString = $result->toJson();

    expect(json_decode($jsonString, true))->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});
