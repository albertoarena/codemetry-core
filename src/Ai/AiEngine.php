<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai;

/**
 * Interface for AI explanation engines.
 *
 * Engines receive metrics-only input (no raw code or diffs) and produce
 * human-readable explanations with optional bounded score adjustments.
 */
interface AiEngine
{
    /**
     * Unique identifier for this engine.
     */
    public function id(): string;

    /**
     * Generate an AI summary for the mood result.
     *
     * @throws AiEngineException If the engine fails (missing API key, network error, etc.)
     */
    public function summarize(MoodAiInput $input): MoodAiSummary;
}
