<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai;

use Codemetry\Core\Ai\Engines\AnthropicEngine;
use Codemetry\Core\Ai\Engines\DeepSeekEngine;
use Codemetry\Core\Ai\Engines\GoogleEngine;
use Codemetry\Core\Ai\Engines\OpenAiEngine;

/**
 * Factory for creating AI engines based on configuration.
 */
final class AiEngineFactory
{
    /**
     * Create an AI engine instance based on the engine identifier.
     *
     * @param string $engine Engine identifier (openai, anthropic, deepseek, google)
     * @param array<string, mixed> $config Engine configuration (api_key, model, base_url, timeout)
     * @throws AiEngineException If the engine is unknown
     */
    public static function create(string $engine, array $config = []): AiEngine
    {
        return match ($engine) {
            'openai' => new OpenAiEngine($config),
            'anthropic' => new AnthropicEngine($config),
            'deepseek' => new DeepSeekEngine($config),
            'google' => new GoogleEngine($config),
            default => throw AiEngineException::unknownEngine($engine),
        };
    }

    /**
     * Get list of supported engine identifiers.
     *
     * @return array<string>
     */
    public static function supportedEngines(): array
    {
        return ['openai', 'anthropic', 'deepseek', 'google'];
    }
}
