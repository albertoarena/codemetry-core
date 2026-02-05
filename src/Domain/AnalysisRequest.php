<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class AnalysisRequest
{
    public function __construct(
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $until = null,
        public ?int $days = null,
        public ?string $author = null,
        public ?string $branch = null,
        public ?\DateTimeZone $timezone = null,
        public int $baselineDays = 56,
        public int $followUpHorizonDays = 3,
        public bool $aiEnabled = false,
        public string $aiEngine = 'openai',
        public string $outputFormat = 'json',
    ) {}

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'since' => $this->since?->format('c'),
            'until' => $this->until?->format('c'),
            'days' => $this->days,
            'author' => $this->author,
            'branch' => $this->branch,
            'timezone' => $this->timezone?->getName(),
            'baseline_days' => $this->baselineDays,
            'follow_up_horizon_days' => $this->followUpHorizonDays,
            'ai_enabled' => $this->aiEnabled,
            'ai_engine' => $this->aiEngine,
            'output_format' => $this->outputFormat,
        ];
    }
}
