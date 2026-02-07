<?php

declare(strict_types=1);

namespace Codemetry\Core\Signals;

use Codemetry\Core\Domain\Confounder;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\SignalSet;

final class ProviderRegistry
{
    /** @var array<SignalProvider> */
    private array $providers = [];

    public function register(SignalProvider $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    /**
     * @return array{signals: SignalSet, confounders: array<string>}
     */
    public function collect(RepoSnapshot $snapshot, ProviderContext $ctx): array
    {
        $merged = new SignalSet($snapshot->window->label);
        $confounders = [];

        foreach ($this->providers as $provider) {
            try {
                $signals = $provider->provide($snapshot, $ctx);
                $merged = $merged->merge($signals);
            } catch (\Exception) {
                $confounders[] = Confounder::providerSkipped($provider->id());
            }
        }

        return [
            'signals' => $merged,
            'confounders' => $confounders,
        ];
    }

    /**
     * @return array<string>
     */
    public function ids(): array
    {
        return array_keys($this->providers);
    }
}
