<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class ReasonItem implements \JsonSerializable
{
    public function __construct(
        public string $signalKey,
        public Direction $direction,
        public float $magnitude,
        public string $summary,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'signal_key' => $this->signalKey,
            'direction' => $this->direction->value,
            'magnitude' => $this->magnitude,
            'summary' => $this->summary,
        ];
    }
}
