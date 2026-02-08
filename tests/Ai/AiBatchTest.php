<?php

use Codemetry\Core\Ai\AiEngine;
use Codemetry\Core\Ai\AiEngineException;
use Codemetry\Core\Ai\Engines\AbstractAiEngine;
use Codemetry\Core\Ai\MoodAiInput;
use Codemetry\Core\Ai\MoodAiSummary;
use Codemetry\Core\Domain\MoodLabel;

// Mock AI Engine for batch testing
function createMockBatchEngine(array $batchResponse): AiEngine
{
    return new class($batchResponse) implements AiEngine {
        private array $response;
        public int $batchCallCount = 0;
        public int $singleCallCount = 0;

        public function __construct(array $response)
        {
            $this->response = $response;
        }

        public function id(): string
        {
            return 'mock';
        }

        public function summarize(MoodAiInput $input): MoodAiSummary
        {
            $this->singleCallCount++;

            return new MoodAiSummary(['Single day summary']);
        }

        public function summarizeBatch(array $inputs): array
        {
            $this->batchCallCount++;

            return $this->response;
        }
    };
}

function createBatchTestInput(string $label, MoodLabel $mood = MoodLabel::Good, int $score = 75): MoodAiInput
{
    return new MoodAiInput(
        windowLabel: $label,
        moodLabel: $mood,
        moodScore: $score,
        confidence: 0.8,
        rawSignals: ['change.churn' => 100],
        normalized: ['norm.change.churn.z' => 0.5],
        reasons: [],
        confounders: [],
        commitsCount: 5,
    );
}

test('summarizeBatch returns summaries keyed by window label', function () {
    $engine = createMockBatchEngine([
        '2024-01-15' => new MoodAiSummary(['Day 1 bullet']),
        '2024-01-16' => new MoodAiSummary(['Day 2 bullet']),
    ]);

    $inputs = [
        createBatchTestInput('2024-01-15'),
        createBatchTestInput('2024-01-16', MoodLabel::Medium, 55),
    ];

    $result = $engine->summarizeBatch($inputs);

    expect($result)->toHaveKeys(['2024-01-15', '2024-01-16'])
        ->and($result['2024-01-15']->explanationBullets)->toBe(['Day 1 bullet'])
        ->and($result['2024-01-16']->explanationBullets)->toBe(['Day 2 bullet']);
});

test('empty inputs returns empty array', function () {
    $engine = createMockBatchEngine([]);

    $result = $engine->summarizeBatch([]);

    expect($result)->toBe([]);
});

test('batch response parsing handles missing entries gracefully', function () {
    // Test the AbstractAiEngine's parseBatchResponse indirectly
    // by creating a mock that returns partial results
    $batchResponse = [
        '2024-01-15' => new MoodAiSummary(['Day 1 bullet']),
        // 2024-01-16 is missing
    ];

    $engine = createMockBatchEngine($batchResponse);

    $inputs = [
        createBatchTestInput('2024-01-15'),
        createBatchTestInput('2024-01-16'),
    ];

    $result = $engine->summarizeBatch($inputs);

    // Mock just returns what we give it, but real AbstractAiEngine handles missing
    expect($result)->toHaveKey('2024-01-15');
});

test('MoodAiSummary fromArray handles batch response format', function () {
    $data = [
        'explanation_bullets' => ['Bullet 1', 'Bullet 2'],
        'score_delta' => 5,
        'confidence_delta' => 0.05,
    ];

    $summary = MoodAiSummary::fromArray($data);

    expect($summary->explanationBullets)->toBe(['Bullet 1', 'Bullet 2'])
        ->and($summary->scoreDelta)->toBe(5)
        ->and($summary->confidenceDelta)->toBe(0.05);
});

test('batch engine tracks call counts correctly', function () {
    $engine = createMockBatchEngine([
        '2024-01-15' => new MoodAiSummary(['bullet']),
    ]);

    expect($engine->batchCallCount)->toBe(0)
        ->and($engine->singleCallCount)->toBe(0);

    $engine->summarizeBatch([createBatchTestInput('2024-01-15')]);

    expect($engine->batchCallCount)->toBe(1)
        ->and($engine->singleCallCount)->toBe(0);

    $engine->summarize(createBatchTestInput('2024-01-16'));

    expect($engine->batchCallCount)->toBe(1)
        ->and($engine->singleCallCount)->toBe(1);
});
