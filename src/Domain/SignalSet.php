<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class SignalSet implements \JsonSerializable
{
    /**
     * @param array<string, Signal> $signals
     */
    public function __construct(
        public string $windowLabel,
        public array $signals = [],
    ) {}

    public function get(string $key): ?Signal
    {
        return $this->signals[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->signals[$key]);
    }

    /**
     * @return array<string, Signal>
     */
    public function all(): array
    {
        return $this->signals;
    }

    public function merge(self $other): self
    {
        return new self(
            windowLabel: $this->windowLabel,
            signals: array_merge($this->signals, $other->signals),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'window_label' => $this->windowLabel,
            'signals' => $this->signals,
        ];
    }
}
