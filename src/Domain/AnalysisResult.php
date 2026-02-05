<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class AnalysisResult implements \JsonSerializable
{
    public const string SCHEMA_VERSION = '1.0';

    /**
     * @param array<string, mixed> $requestSummary
     * @param array<MoodResult> $windows
     */
    public function __construct(
        public string $repoId,
        public \DateTimeImmutable $analyzedAt,
        public array $requestSummary,
        public array $windows,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo_id' => $this->repoId,
            'analyzed_at' => $this->analyzedAt->format('c'),
            'request_summary' => $this->requestSummary,
            'windows' => $this->windows,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | $flags);
    }
}
