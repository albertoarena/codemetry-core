<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class AnalysisWindow implements \JsonSerializable
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public string $label,
    ) {}

    public function durationSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'start' => $this->start->format('c'),
            'end' => $this->end->format('c'),
            'label' => $this->label,
            'duration_seconds' => $this->durationSeconds(),
        ];
    }
}
