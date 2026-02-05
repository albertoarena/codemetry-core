<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

enum Direction: string
{
    case Positive = 'positive';
    case Negative = 'negative';
}
