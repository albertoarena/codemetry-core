<?php

declare(strict_types=1);

namespace Codemetry\Core;

use Codemetry\Core\Baseline\BaselineBuilder;
use Codemetry\Core\Baseline\BaselineCache;
use Codemetry\Core\Baseline\Normalizer;
use Codemetry\Core\Domain\AnalysisRequest;
use Codemetry\Core\Domain\AnalysisResult;
use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Git\GitRepoReader;
use Codemetry\Core\Scoring\HeuristicScorer;
use Codemetry\Core\Signals\ProviderContext;
use Codemetry\Core\Signals\ProviderRegistry;
use Codemetry\Core\Signals\Providers\ChangeShapeProvider;
use Codemetry\Core\Signals\Providers\CommitMessageProvider;
use Codemetry\Core\Signals\Providers\FollowUpFixProvider;

final class Analyzer
{
    private readonly GitRepoReader $gitReader;
    private readonly ProviderRegistry $registry;
    private readonly Normalizer $normalizer;
    private readonly HeuristicScorer $scorer;
    private readonly BaselineCache $cache;

    public function __construct(
        ?GitRepoReader $gitReader = null,
        ?ProviderRegistry $registry = null,
    ) {
        $this->gitReader = $gitReader ?? new GitRepoReader();
        $this->registry = $registry ?? $this->defaultRegistry();
        $this->normalizer = new Normalizer();
        $this->scorer = new HeuristicScorer();
        $this->cache = new BaselineCache();
    }

    public function analyze(string $repoPath, AnalysisRequest $request): AnalysisResult
    {
        $this->gitReader->validateRepo($repoPath);

        $windows = $this->resolveWindows($request);
        $config = $this->buildConfig($request);

        $baseline = $this->resolveBaseline($repoPath, $windows, $request, $config);

        $ctx = new ProviderContext($repoPath, $config, $this->gitReader);
        $moodResults = [];

        foreach ($windows as $window) {
            $snapshot = $this->gitReader->buildSnapshot($repoPath, $window, $request->author, $request->branch);
            $collected = $this->registry->collect($snapshot, $ctx);

            $features = $this->normalizer->normalize($collected['signals'], $baseline);
            $mood = $this->scorer->score($features, $collected['confounders']);

            $moodResults[] = $mood;
        }

        return new AnalysisResult(
            repoId: $this->repoId($repoPath),
            analyzedAt: new \DateTimeImmutable(),
            requestSummary: $request->toSummary(),
            windows: $moodResults,
        );
    }

    /**
     * @return array<AnalysisWindow>
     */
    private function resolveWindows(AnalysisRequest $request): array
    {
        $tz = $request->timezone ?? new \DateTimeZone('UTC');

        if ($request->since !== null && $request->until !== null) {
            return $this->generateDailyWindows($request->since, $request->until, $tz);
        }

        $until = $request->until ?? new \DateTimeImmutable('now', $tz);
        $days = $request->days ?? 7;

        if ($request->since !== null) {
            return $this->generateDailyWindows($request->since, $until, $tz);
        }

        $since = $until->modify("-{$days} days");

        return $this->generateDailyWindows($since, $until, $tz);
    }

    /**
     * @return array<AnalysisWindow>
     */
    private function generateDailyWindows(
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
        \DateTimeZone $tz,
    ): array {
        $windows = [];
        $current = $since->setTimezone($tz)->setTime(0, 0, 0);
        $end = $until->setTimezone($tz)->setTime(0, 0, 0);

        while ($current < $end) {
            $dayEnd = $current->modify('+1 day');

            $windows[] = new AnalysisWindow(
                start: $current,
                end: $dayEnd,
                label: $current->format('Y-m-d'),
            );

            $current = $dayEnd;
        }

        return $windows;
    }

    /**
     * @param array<AnalysisWindow> $windows
     * @return array<string, mixed>
     */
    private function buildConfig(AnalysisRequest $request): array
    {
        return [
            'follow_up_horizon_days' => $request->followUpHorizonDays,
        ];
    }

    /**
     * @param array<AnalysisWindow> $windows
     */
    private function resolveBaseline(
        string $repoPath,
        array $windows,
        AnalysisRequest $request,
        array $config,
    ): \Codemetry\Core\Baseline\Baseline {
        $providerIds = $this->registry->ids();

        $cached = $this->cache->load($repoPath, $request->baselineDays, $providerIds, $config);
        if ($cached !== null) {
            return $cached;
        }

        $earliest = $windows[0]->start ?? new \DateTimeImmutable();
        $builder = new BaselineBuilder($this->gitReader, $this->registry);
        $baseline = $builder->build($repoPath, $earliest, $request->baselineDays, $config);

        $this->cache->save($repoPath, $baseline, $request->baselineDays, $providerIds, $config);

        return $baseline;
    }

    private function repoId(string $repoPath): string
    {
        return md5(realpath($repoPath) ?: $repoPath);
    }

    private function defaultRegistry(): ProviderRegistry
    {
        $registry = new ProviderRegistry();
        $registry->register(new ChangeShapeProvider());
        $registry->register(new CommitMessageProvider());
        $registry->register(new FollowUpFixProvider());

        return $registry;
    }
}
