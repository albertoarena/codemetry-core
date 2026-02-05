<?php

use Codemetry\Core\Domain\Direction;
use Codemetry\Core\Domain\MoodLabel;
use Codemetry\Core\Domain\MoodResult;
use Codemetry\Core\Domain\ReasonItem;

test('mood label maps from score', function () {
    expect(MoodLabel::fromScore(0))->toBe(MoodLabel::Bad)
        ->and(MoodLabel::fromScore(44))->toBe(MoodLabel::Bad)
        ->and(MoodLabel::fromScore(45))->toBe(MoodLabel::Medium)
        ->and(MoodLabel::fromScore(74))->toBe(MoodLabel::Medium)
        ->and(MoodLabel::fromScore(75))->toBe(MoodLabel::Good)
        ->and(MoodLabel::fromScore(100))->toBe(MoodLabel::Good);
});

test('mood result serializes to JSON', function () {
    $reason = new ReasonItem(
        signalKey: 'change.churn',
        direction: Direction::Negative,
        magnitude: 20.0,
        summary: 'High churn at p95',
    );

    $result = new MoodResult(
        windowLabel: '2024-01-01',
        moodLabel: MoodLabel::Medium,
        moodScore: 58,
        confidence: 0.7,
        reasons: [$reason],
        confounders: ['large_refactor_suspected'],
    );

    $json = json_decode(json_encode($result), true);

    expect($json['window_label'])->toBe('2024-01-01')
        ->and($json['mood_label'])->toBe('medium')
        ->and($json['mood_score'])->toBe(58)
        ->and($json['confidence'])->toBe(0.7)
        ->and($json['reasons'])->toHaveCount(1)
        ->and($json['reasons'][0]['signal_key'])->toBe('change.churn')
        ->and($json['reasons'][0]['direction'])->toBe('negative')
        ->and($json['reasons'][0]['magnitude'])->toEqual(20.0)
        ->and($json['confounders'])->toBe(['large_refactor_suspected']);
});
