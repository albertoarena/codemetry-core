<?php

use Codemetry\Core\Ai\AiEngineException;

test('missing api key exception', function () {
    $e = AiEngineException::missingApiKey('openai');

    expect($e->getMessage())->toBe('API key not configured for AI engine: openai');
});

test('request failed exception', function () {
    $e = AiEngineException::requestFailed('anthropic', 'Connection timeout');

    expect($e->getMessage())->toBe("AI engine 'anthropic' request failed: Connection timeout");
});

test('invalid response exception', function () {
    $e = AiEngineException::invalidResponse('deepseek', 'Not valid JSON');

    expect($e->getMessage())->toBe("AI engine 'deepseek' returned invalid response: Not valid JSON");
});

test('unknown engine exception', function () {
    $e = AiEngineException::unknownEngine('fake-ai');

    expect($e->getMessage())->toBe('Unknown AI engine: fake-ai');
});
