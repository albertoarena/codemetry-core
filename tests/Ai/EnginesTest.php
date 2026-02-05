<?php

use Codemetry\Core\Ai\AiEngineException;
use Codemetry\Core\Ai\Engines\AnthropicEngine;
use Codemetry\Core\Ai\Engines\DeepSeekEngine;
use Codemetry\Core\Ai\Engines\GoogleEngine;
use Codemetry\Core\Ai\Engines\OpenAiEngine;
use Codemetry\Core\Ai\MoodAiInput;
use Codemetry\Core\Domain\MoodLabel;

// --- OpenAI Engine ---

test('openai engine throws when api key missing', function () {
    $engine = new OpenAiEngine([]);
    $input = createTestInput();

    $engine->summarize($input);
})->throws(AiEngineException::class, 'API key not configured for AI engine: openai');

test('openai engine has correct id', function () {
    $engine = new OpenAiEngine(['api_key' => 'test']);
    expect($engine->id())->toBe('openai');
});

// --- Anthropic Engine ---

test('anthropic engine throws when api key missing', function () {
    $engine = new AnthropicEngine([]);
    $input = createTestInput();

    $engine->summarize($input);
})->throws(AiEngineException::class, 'API key not configured for AI engine: anthropic');

test('anthropic engine has correct id', function () {
    $engine = new AnthropicEngine(['api_key' => 'test']);
    expect($engine->id())->toBe('anthropic');
});

// --- DeepSeek Engine ---

test('deepseek engine throws when api key missing', function () {
    $engine = new DeepSeekEngine([]);
    $input = createTestInput();

    $engine->summarize($input);
})->throws(AiEngineException::class, 'API key not configured for AI engine: deepseek');

test('deepseek engine has correct id', function () {
    $engine = new DeepSeekEngine(['api_key' => 'test']);
    expect($engine->id())->toBe('deepseek');
});

// --- Google Engine ---

test('google engine throws when api key missing', function () {
    $engine = new GoogleEngine([]);
    $input = createTestInput();

    $engine->summarize($input);
})->throws(AiEngineException::class, 'API key not configured for AI engine: google');

test('google engine has correct id', function () {
    $engine = new GoogleEngine(['api_key' => 'test']);
    expect($engine->id())->toBe('google');
});

// --- Helper ---

function createTestInput(): MoodAiInput
{
    return new MoodAiInput(
        windowLabel: '2024-01-15',
        moodLabel: MoodLabel::Medium,
        moodScore: 65,
        confidence: 0.7,
        rawSignals: ['change.churn' => 500],
        normalized: ['norm.change.churn.z' => 1.5],
        reasons: [],
        confounders: [],
        commitsCount: 5,
    );
}
