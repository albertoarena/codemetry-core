<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

enum MoodLabel: string
{
    case Bad = 'bad';
    case Medium = 'medium';
    case Good = 'good';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score <= 44 => self::Bad,
            $score <= 74 => self::Medium,
            default => self::Good,
        };
    }
}
