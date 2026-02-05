<?php

declare(strict_types=1);

namespace Codemetry\Core\Signals;

use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\SignalSet;

interface SignalProvider
{
    public function id(): string;

    public function provide(RepoSnapshot $snapshot, ProviderContext $ctx): SignalSet;
}
