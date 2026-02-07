<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

/**
 * Constants for confounder strings used in analysis results.
 *
 * Confounders are factors that may affect the reliability of the analysis.
 */
final class Confounder
{
    public const AI_UNAVAILABLE = 'ai_unavailable';
    public const PROVIDER_SKIPPED_PREFIX = 'provider_skipped:';
    public const LARGE_REFACTOR_SUSPECTED = 'large_refactor_suspected';
    public const FORMATTING_OR_RENAME_SUSPECTED = 'formatting_or_rename_suspected';

    /**
     * Generate provider skipped confounder string.
     */
    public static function providerSkipped(string $providerId): string
    {
        return self::PROVIDER_SKIPPED_PREFIX . $providerId;
    }
}
