<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai;

final class AiEngineException extends \RuntimeException
{
    public static function missingApiKey(string $engine): self
    {
        return new self("API key not configured for AI engine: {$engine}");
    }

    public static function requestFailed(string $engine, string $reason): self
    {
        return new self("AI engine '{$engine}' request failed: {$reason}");
    }

    public static function invalidResponse(string $engine, string $reason): self
    {
        return new self("AI engine '{$engine}' returned invalid response: {$reason}");
    }

    public static function unknownEngine(string $engine): self
    {
        return new self("Unknown AI engine: {$engine}");
    }
}
