<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

enum SignalType: string
{
    case Numeric = 'numeric';
    case Boolean = 'boolean';
    case String = 'string';
}
