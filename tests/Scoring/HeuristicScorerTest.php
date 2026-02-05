<?php

use Codemetry\Core\Domain\Direction;
use Codemetry\Core\Domain\MoodLabel;
use Codemetry\Core\Domain\NormalizedFeatureSet;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;
use Codemetry\Core\Scoring\HeuristicScorer;

function buildFeatures(array $rawSignals = [], array $normalized = []): NormalizedFeatureSet
{
    $signals = [];
    foreach ($rawSignals as $key => $value) {
        $signals[$key] = new Signal($key, SignalType::Numeric, $value);
    }

    return new NormalizedFeatureSet(
        new SignalSet('2024-01-15', $signals),
        $normalized,
    );
}

// --- Base score ---

test('returns base score of 70 with no penalties or rewards', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.change.churn.pctl' => 50.0, 'norm.change.scatter.pctl' => 50.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(70)
        ->and($result->moodLabel)->toBe(MoodLabel::Medium);
});

// --- Churn penalties ---

test('applies -20 penalty for churn at p95+', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.change.churn.pctl' => 96.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(50); // 70 - 20
});

test('applies -12 penalty for churn at p90-p95', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.change.churn.pctl' => 92.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(58); // 70 - 12
});

// --- Scatter penalty ---

test('applies -10 penalty for scatter at p90+', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.change.scatter.pctl' => 91.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(60); // 70 - 10
});

// --- Follow-up fix density penalties ---

test('applies -25 penalty for fix density at p95+', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.followup.fix_density.pctl' => 97.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(45); // 70 - 25
});

test('applies -15 penalty for fix density at p90-p95', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.followup.fix_density.pctl' => 91.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(55); // 70 - 15
});

// --- Revert penalty ---

test('applies -15 penalty when reverts detected', function () {
    $features = buildFeatures(
        ['msg.revert_count' => 1, 'change.commits_count' => 5],
        [],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(55); // 70 - 15
});

// --- WIP penalty ---

test('applies -8 penalty for high WIP ratio', function () {
    $features = buildFeatures(
        ['msg.wip_count' => 3, 'change.commits_count' => 5],
        [],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    // wip_ratio = 3/5 = 0.6 >= 0.3
    expect($result->moodScore)->toBe(62); // 70 - 8
});

test('no WIP penalty when ratio below 0.3', function () {
    $features = buildFeatures(
        ['msg.wip_count' => 1, 'change.commits_count' => 10],
        [],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    // wip_ratio = 1/10 = 0.1 < 0.3
    expect($result->moodScore)->toBe(70);
});

// --- Reward ---

test('applies +5 reward for low churn and low fix density', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 20.0,
            'norm.followup.fix_density.pctl' => 15.0,
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->moodScore)->toBe(75); // 70 + 5
    expect($result->moodLabel)->toBe(MoodLabel::Good);
});

// --- Combined penalties ---

test('accumulates multiple penalties', function () {
    $features = buildFeatures(
        ['msg.revert_count' => 2, 'change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 96.0,      // -20
            'norm.change.scatter.pctl' => 92.0,     // -10
            'norm.followup.fix_density.pctl' => 96.0, // -25
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    // 70 - 20 - 10 - 25 - 15(revert) = 0
    expect($result->moodScore)->toBe(0)
        ->and($result->moodLabel)->toBe(MoodLabel::Bad);
});

// --- Score clamping ---

test('clamps score to 0 minimum', function () {
    $features = buildFeatures(
        ['msg.revert_count' => 1, 'msg.wip_count' => 5, 'change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 96.0,
            'norm.change.scatter.pctl' => 92.0,
            'norm.followup.fix_density.pctl' => 96.0,
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    // 70 - 20 - 10 - 25 - 15 - 8 = -8 → clamped to 0
    expect($result->moodScore)->toBe(0);
});

// --- Label mapping ---

test('maps scores to correct labels', function () {
    $scorer = new HeuristicScorer();

    // Bad: score 0-44
    $bad = $scorer->score(buildFeatures(
        ['msg.revert_count' => 1, 'change.commits_count' => 5],
        ['norm.change.churn.pctl' => 96.0], // 70 - 20 - 15 = 35
    ));
    expect($bad->moodLabel)->toBe(MoodLabel::Bad);

    // Medium: score 45-74
    $medium = $scorer->score(buildFeatures(
        ['change.commits_count' => 5],
        ['norm.change.churn.pctl' => 50.0], // 70
    ));
    expect($medium->moodLabel)->toBe(MoodLabel::Medium);

    // Good: score 75-100
    $good = $scorer->score(buildFeatures(
        ['change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 20.0,
            'norm.followup.fix_density.pctl' => 15.0,
        ], // 70 + 5 = 75
    ));
    expect($good->moodLabel)->toBe(MoodLabel::Good);
});

// --- Confidence ---

test('base confidence is 0.6', function () {
    $features = buildFeatures([], []);

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->confidence)->toBe(0.6);
});

test('confidence increases with enough commits and follow-up', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.followup.fix_density.pctl' => 50.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    // 0.6 + 0.1(commits>=3) + 0.1(followup ran) = 0.8
    expect($result->confidence)->toBe(0.8);
});

test('confidence decreases with few commits', function () {
    $features = buildFeatures(
        ['change.commits_count' => 1],
        [],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    // 0.6 - 0.2(commits<=1) = 0.4
    expect($result->confidence)->toBe(0.4);
});

test('confidence decreases for skipped providers', function () {
    $features = buildFeatures(['change.commits_count' => 5], []);

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features, ['provider_skipped:change_shape', 'provider_skipped:commit_message']);

    // 0.6 + 0.1(commits>=3) - 0.1(change_shape) - 0.1(commit_message) = 0.5
    expect($result->confidence)->toBe(0.5);
});

test('confidence clamped to 0..1', function () {
    $features = buildFeatures(
        ['change.commits_count' => 0],
        [],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features, [
        'provider_skipped:change_shape',
        'provider_skipped:follow_up_fix',
        'provider_skipped:commit_message',
    ]);

    // 0.6 - 0.2(commits<=1) - 0.3(skipped) = 0.1
    expect($result->confidence)->toBeGreaterThanOrEqual(0.0)
        ->and($result->confidence)->toBeLessThanOrEqual(1.0);
});

// --- Reasons ---

test('reasons sorted by magnitude descending', function () {
    $features = buildFeatures(
        ['msg.revert_count' => 1, 'change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 96.0,      // -20
            'norm.change.scatter.pctl' => 92.0,     // -10
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->reasons)->toHaveCount(3);
    // Sorted: churn(20), revert(15), scatter(10)
    expect($result->reasons[0]->magnitude)->toBeGreaterThanOrEqual($result->reasons[1]->magnitude)
        ->and($result->reasons[1]->magnitude)->toBeGreaterThanOrEqual($result->reasons[2]->magnitude);
});

test('reasons capped at 6', function () {
    $features = buildFeatures(
        [
            'msg.revert_count' => 1,
            'msg.wip_count' => 5,
            'change.commits_count' => 5,
        ],
        [
            'norm.change.churn.pctl' => 96.0,
            'norm.change.scatter.pctl' => 92.0,
            'norm.followup.fix_density.pctl' => 96.0,
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect(count($result->reasons))->toBeLessThanOrEqual(6);
});

// --- Confounders ---

test('detects large refactor suspected', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 96.0,
            'norm.followup.fix_density.pctl' => 30.0,
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->confounders)->toContain('large_refactor_suspected');
});

test('detects formatting or rename suspected', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 97.0,
            'norm.change.files_touched.pctl' => 95.0,
            'norm.followup.fix_density.pctl' => 10.0,
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->confounders)->toContain('formatting_or_rename_suspected');
});

test('preserves existing confounders', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        ['norm.change.churn.pctl' => 50.0],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features, ['provider_skipped:some_tool']);

    expect($result->confounders)->toContain('provider_skipped:some_tool');
});

test('no duplicate confounders', function () {
    $features = buildFeatures(
        ['change.commits_count' => 5],
        [
            'norm.change.churn.pctl' => 97.0,
            'norm.change.files_touched.pctl' => 95.0,
            'norm.followup.fix_density.pctl' => 10.0,
        ],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features, ['formatting_or_rename_suspected']);

    $counts = array_count_values($result->confounders);
    expect($counts['formatting_or_rename_suspected'])->toBe(1);
});

// --- Output includes raw signals and normalized ---

test('result includes raw signals and normalized data', function () {
    $features = buildFeatures(
        ['change.churn' => 100, 'change.commits_count' => 3],
        ['norm.change.churn.pctl' => 50.0, 'norm.change.churn.z' => 0.5],
    );

    $scorer = new HeuristicScorer();
    $result = $scorer->score($features);

    expect($result->rawSignals)->not->toBeNull()
        ->and($result->rawSignals->get('change.churn')->value)->toBe(100)
        ->and($result->normalized)->toHaveKey('norm.change.churn.pctl')
        ->and($result->windowLabel)->toBe('2024-01-15');
});
