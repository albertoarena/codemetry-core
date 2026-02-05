<?php

declare(strict_types=1);

namespace Codemetry\Core\Exception;

final class GitCommandException extends \RuntimeException
{
    public static function failed(string $command, string $error): self
    {
        return new self("Git command failed [{$command}]: {$error}");
    }
}
