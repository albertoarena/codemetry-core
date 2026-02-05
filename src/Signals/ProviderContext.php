<?php

declare(strict_types=1);

namespace Codemetry\Core\Signals;

use Codemetry\Core\Git\GitRepoReader;

final readonly class ProviderContext
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $repoPath,
        public array $config = [],
        public ?GitRepoReader $gitReader = null,
    ) {}
}
