<?php

use Codemetry\Core\Ai\AiEngineException;
use Codemetry\Core\Ai\AiEngineFactory;
use Codemetry\Core\Ai\Engines\AnthropicEngine;
use Codemetry\Core\Ai\Engines\DeepSeekEngine;
use Codemetry\Core\Ai\Engines\GoogleEngine;
use Codemetry\Core\Ai\Engines\OpenAiEngine;

test('creates openai engine', function () {
    $engine = AiEngineFactory::create('openai', ['api_key' => 'test-key']);

    expect($engine)->toBeInstanceOf(OpenAiEngine::class)
        ->and($engine->id())->toBe('openai');
});

test('creates anthropic engine', function () {
    $engine = AiEngineFactory::create('anthropic', ['api_key' => 'test-key']);

    expect($engine)->toBeInstanceOf(AnthropicEngine::class)
        ->and($engine->id())->toBe('anthropic');
});

test('creates deepseek engine', function () {
    $engine = AiEngineFactory::create('deepseek', ['api_key' => 'test-key']);

    expect($engine)->toBeInstanceOf(DeepSeekEngine::class)
        ->and($engine->id())->toBe('deepseek');
});

test('creates google engine', function () {
    $engine = AiEngineFactory::create('google', ['api_key' => 'test-key']);

    expect($engine)->toBeInstanceOf(GoogleEngine::class)
        ->and($engine->id())->toBe('google');
});

test('throws for unknown engine', function () {
    AiEngineFactory::create('unknown-engine');
})->throws(AiEngineException::class, 'Unknown AI engine: unknown-engine');

test('returns supported engines list', function () {
    $supported = AiEngineFactory::supportedEngines();

    expect($supported)->toContain('openai')
        ->and($supported)->toContain('anthropic')
        ->and($supported)->toContain('deepseek')
        ->and($supported)->toContain('google');
});
