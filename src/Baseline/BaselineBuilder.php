<?php

declare(strict_types=1);

namespace Codemetry\Core\Baseline;

use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\SignalType;
use Codemetry\Core\Git\GitRepoReader;
use Codemetry\Core\Signals\ProviderContext;
use Codemetry\Core\Signals\ProviderRegistry;

final class BaselineBuilder
{
    public function __construct(
        private readonly GitRepoReader $gitReader,
        private readonly ProviderRegistry $registry,
    ) {}

    public function build(
        string $repoPath,
        \DateTimeImmutable $until,
        int $baselineDays,
        array $config = [],
    ): Baseline {
        $windows = $this->generateDailyWindows($until, $baselineDays);

        /** @var array<string, array<int|float>> $signalValues */
        $signalValues = [];
        $windowCount = 0;

        $ctx = new ProviderContext($repoPath, $config, $this->gitReader);

        foreach ($windows as $window) {
            $snapshot = $this->gitReader->buildSnapshot($repoPath, $window);
            $result = $this->registry->collect($snapshot, $ctx);

            foreach ($result['signals']->signals as $key => $signal) {
                if ($signal->type !== SignalType::Numeric) {
                    continue;
                }

                $signalValues[$key][] = (float) $signal->value;
            }

            $windowCount++;
        }

        $distributions = [];
        foreach ($signalValues as $key => $values) {
            $distributions[$key] = BaselineDistribution::fromValues($values);
        }

        return new Baseline($distributions, $windowCount);
    }

    /**
     * @return array<AnalysisWindow>
     */
    private function generateDailyWindows(\DateTimeImmutable $until, int $days): array
    {
        $windows = [];
        $tz = $until->getTimezone();

        for ($i = $days; $i >= 1; $i--) {
            $dayStart = $until->modify("-{$i} days")->setTime(0, 0, 0);
            $dayEnd = $dayStart->modify('+1 day');

            $windows[] = new AnalysisWindow(
                start: $dayStart,
                end: $dayEnd,
                label: $dayStart->format('Y-m-d'),
            );
        }

        return $windows;
    }
}
