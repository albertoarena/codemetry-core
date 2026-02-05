<?php

declare(strict_types=1);

namespace Codemetry\Core\Exception;

final class InvalidRepoException extends \RuntimeException
{
    public static function notAGitRepo(string $path): self
    {
        return new self("Path is not inside a Git repository: {$path}");
    }

    public static function pathNotFound(string $path): self
    {
        return new self("Path does not exist: {$path}");
    }
}
