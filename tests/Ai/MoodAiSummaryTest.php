<?php

use Codemetry\Core\Ai\MoodAiSummary;
use Codemetry\Core\Domain\MoodLabel;

test('creates summary with defaults', function () {
    $summary = new MoodAiSummary();

    expect($summary->explanationBullets)->toBe([])
        ->and($summary->scoreDelta)->toBe(0)
        ->and($summary->confidenceDelta)->toBe(0.0)
        ->and($summary->labelOverride)->toBeNull();
});

test('creates summary from array', function () {
    $data = [
        'explanation_bullets' => [
            'High code churn indicates active development',
            'Follow-up fixes suggest quality issues',
        ],
        'score_delta' => -5,
        'confidence_delta' => 0.05,
    ];

    $summary = MoodAiSummary::fromArray($data);

    expect($summary->explanationBullets)->toHaveCount(2)
        ->and($summary->explanationBullets[0])->toBe('High code churn indicates active development')
        ->and($summary->scoreDelta)->toBe(-5)
        ->and($summary->confidenceDelta)->toBe(0.05)
        ->and($summary->labelOverride)->toBeNull();
});

test('clamps score delta to bounds', function () {
    $data = ['score_delta' => 50];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->scoreDelta)->toBe(10);

    $data = ['score_delta' => -50];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->scoreDelta)->toBe(-10);
});

test('clamps confidence delta to bounds', function () {
    $data = ['confidence_delta' => 0.5];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->confidenceDelta)->toBe(0.1);

    $data = ['confidence_delta' => -0.5];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->confidenceDelta)->toBe(-0.1);
});

test('parses label override', function () {
    $data = ['label_override' => 'bad'];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->labelOverride)->toBe(MoodLabel::Bad);

    $data = ['label_override' => 'good'];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->labelOverride)->toBe(MoodLabel::Good);

    $data = ['label_override' => 'invalid'];
    $summary = MoodAiSummary::fromArray($data);
    expect($summary->labelOverride)->toBeNull();
});

test('handles camelCase keys', function () {
    $data = [
        'explanationBullets' => ['Bullet one'],
        'scoreDelta' => 3,
        'confidenceDelta' => 0.02,
        'labelOverride' => 'medium',
    ];

    $summary = MoodAiSummary::fromArray($data);

    expect($summary->explanationBullets)->toBe(['Bullet one'])
        ->and($summary->scoreDelta)->toBe(3)
        ->and($summary->confidenceDelta)->toBe(0.02)
        ->and($summary->labelOverride)->toBe(MoodLabel::Medium);
});

test('filters non-string bullets', function () {
    $data = [
        'explanation_bullets' => ['Valid bullet', 123, null, 'Another valid'],
    ];

    $summary = MoodAiSummary::fromArray($data);

    expect($summary->explanationBullets)->toBe(['Valid bullet', 'Another valid']);
});

test('serializes to JSON', function () {
    $summary = new MoodAiSummary(
        explanationBullets: ['Point one', 'Point two'],
        scoreDelta: -3,
        confidenceDelta: 0.05,
        labelOverride: MoodLabel::Bad,
    );

    $json = json_encode($summary, JSON_PRETTY_PRINT);
    $decoded = json_decode($json, true);

    expect($decoded['explanation_bullets'])->toBe(['Point one', 'Point two'])
        ->and($decoded['score_delta'])->toBe(-3)
        ->and($decoded['confidence_delta'])->toBe(0.05)
        ->and($decoded['label_override'])->toBe('bad');
});
