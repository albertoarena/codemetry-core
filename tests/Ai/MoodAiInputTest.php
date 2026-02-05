<?php

use Codemetry\Core\Ai\MoodAiInput;
use Codemetry\Core\Domain\Direction;
use Codemetry\Core\Domain\MoodLabel;
use Codemetry\Core\Domain\ReasonItem;

test('mood ai input serializes to JSON', function () {
    $reasons = [
        new ReasonItem('change.churn', Direction::Negative, 15.0, 'High churn detected'),
    ];

    $input = new MoodAiInput(
        windowLabel: '2024-01-15',
        moodLabel: MoodLabel::Medium,
        moodScore: 65,
        confidence: 0.7,
        rawSignals: ['change.churn' => 500, 'change.commits_count' => 5],
        normalized: ['norm.change.churn.z' => 1.5, 'norm.change.churn.pctl' => 85.0],
        reasons: $reasons,
        confounders: [],
        commitsCount: 5,
        extensionHistogram: ['php' => 10, 'js' => 5],
        topPaths: ['src/App.php', 'src/Auth.php'],
    );

    $json = json_encode($input, JSON_PRETTY_PRINT);

    expect($json)->toBeString();

    $decoded = json_decode($json, true);

    expect($decoded['window_label'])->toBe('2024-01-15')
        ->and($decoded['mood_label'])->toBe('medium')
        ->and($decoded['mood_score'])->toBe(65)
        ->and($decoded['confidence'])->toBe(0.7)
        ->and($decoded['raw_signals']['change.churn'])->toBe(500)
        ->and($decoded['normalized']['norm.change.churn.z'])->toBe(1.5)
        ->and($decoded['commits_count'])->toBe(5)
        ->and($decoded['extension_histogram']['php'])->toBe(10)
        ->and($decoded['top_paths'])->toContain('src/App.php');
});

test('mood ai input contains metrics only, no code', function () {
    $input = new MoodAiInput(
        windowLabel: '2024-01-15',
        moodLabel: MoodLabel::Good,
        moodScore: 80,
        confidence: 0.8,
        rawSignals: ['change.churn' => 100],
        normalized: [],
        reasons: [],
        confounders: [],
        commitsCount: 3,
    );

    $json = json_encode($input);

    // Should not contain code snippets or diffs
    expect($json)->not->toContain('<?php')
        ->and($json)->not->toContain('function ')
        ->and($json)->not->toContain('class ');
});
