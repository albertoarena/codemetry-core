<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class Signal implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public SignalType $type,
        public int|float|bool|string $value,
        public string $description = '',
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'value' => $this->value,
            'description' => $this->description,
        ];
    }
}
