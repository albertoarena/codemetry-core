<?php

declare(strict_types=1);

namespace Codemetry\Core;

use Codemetry\Core\Ai\AiEngine;
use Codemetry\Core\Ai\AiEngineException;
use Codemetry\Core\Ai\AiEngineFactory;
use Codemetry\Core\Ai\MoodAiInput;
use Codemetry\Core\Baseline\BaselineBuilder;
use Codemetry\Core\Baseline\BaselineCache;
use Codemetry\Core\Baseline\Normalizer;
use Codemetry\Core\Domain\AnalysisRequest;
use Codemetry\Core\Domain\AnalysisResult;
use Codemetry\Core\Domain\AnalysisWindow;
use Codemetry\Core\Domain\Confounder;
use Codemetry\Core\Domain\MoodResult;
use Codemetry\Core\Domain\RepoSnapshot;
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

    /**
     * Analyze a Git repository and produce mood proxy results.
     *
     * @param string $repoPath Path to the Git repository
     * @param AnalysisRequest $request Analysis configuration
     * @param array<string, mixed> $externalConfig External config (e.g., AI API keys from framework config)
     */
    public function analyze(string $repoPath, AnalysisRequest $request, array $externalConfig = []): AnalysisResult
    {
        $this->gitReader->validateRepo($repoPath);

        $windows = $this->resolveWindows($request);
        $config = $this->buildConfig($request, $externalConfig);

        $baseline = $this->resolveBaseline($repoPath, $windows, $request, $config);

        $ctx = new ProviderContext($repoPath, $config, $this->gitReader);
        $aiEngine = $this->resolveAiEngine($request, $config);
        $moodResults = [];
        $snapshots = [];

        // First pass: build all mood results without AI enhancement
        foreach ($windows as $window) {
            $snapshot = $this->gitReader->buildSnapshot($repoPath, $window, $request->author, $request->branch);
            $collected = $this->registry->collect($snapshot, $ctx);

            $features = $this->normalizer->normalize($collected['signals'], $baseline);
            $confounders = $collected['confounders'];

            $mood = $this->scorer->score($features, $confounders);
            $moodResults[] = $mood;
            $snapshots[] = $snapshot;
        }

        // Second pass: apply AI enhancement in batches if enabled
        if ($aiEngine !== null) {
            $batchSize = (int) ($config['ai']['batch_size'] ?? 10);
            $moodResults = $this->applyAiBatchEnhancement($aiEngine, $moodResults, $snapshots, $batchSize);
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
     * @param array<string, mixed> $externalConfig
     * @return array<string, mixed>
     */
    private function buildConfig(AnalysisRequest $request, array $externalConfig = []): array
    {
        return array_merge([
            'follow_up_horizon_days' => $request->followUpHorizonDays,
        ], $externalConfig);
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

        $earliest = count($windows) > 0
            ? $windows[0]->start
            : new \DateTimeImmutable();
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

    /**
     * @param array<string, mixed> $config
     */
    private function resolveAiEngine(AnalysisRequest $request, array $config): ?AiEngine
    {
        if (!$request->aiEnabled) {
            return null;
        }

        $aiConfig = $config['ai'] ?? [];
        if (empty($aiConfig['api_key'])) {
            return null;
        }

        try {
            return AiEngineFactory::create($request->aiEngine, $aiConfig);
        } catch (AiEngineException) {
            return null;
        }
    }

    private ?string $lastAiError = null;

    /**
     * Get the last AI error message, if any.
     */
    public function getLastAiError(): ?string
    {
        return $this->lastAiError;
    }

    /**
     * Apply AI enhancement to mood results in batches.
     *
     * @param array<MoodResult> $moodResults
     * @param array<RepoSnapshot> $snapshots
     * @return array<MoodResult>
     */
    private function applyAiBatchEnhancement(
        AiEngine $engine,
        array $moodResults,
        array $snapshots,
        int $batchSize,
    ): array {
        // Build all AI inputs
        $inputs = [];
        foreach ($moodResults as $index => $mood) {
            $inputs[$index] = $this->buildAiInput($mood, $snapshots[$index]);
        }

        // Process in batches
        $batches = array_chunk($inputs, $batchSize, true);
        $allSummaries = [];

        foreach ($batches as $batch) {
            try {
                $summaries = $engine->summarizeBatch(array_values($batch));
                $allSummaries = array_merge($allSummaries, $summaries);
            } catch (AiEngineException $e) {
                $this->lastAiError = $e->getMessage();

                // Mark all remaining moods with ai_unavailable confounder
                foreach ($moodResults as $index => $mood) {
                    if (!isset($allSummaries[$mood->windowLabel])) {
                        $moodResults[$index] = $this->addAiUnavailableConfounder($mood);
                    }
                }

                break;
            }
        }

        // Apply summaries to mood results
        foreach ($moodResults as $index => $mood) {
            $label = $mood->windowLabel;
            if (isset($allSummaries[$label])) {
                $moodResults[$index] = $mood->withAiSummary($allSummaries[$label]);
            }
        }

        return $moodResults;
    }

    /**
     * Add ai_unavailable confounder to a mood result.
     */
    private function addAiUnavailableConfounder(MoodResult $mood): MoodResult
    {
        $confounders = $mood->confounders;
        if (!in_array(Confounder::AI_UNAVAILABLE, $confounders, true)) {
            $confounders[] = Confounder::AI_UNAVAILABLE;
        }

        return new MoodResult(
            windowLabel: $mood->windowLabel,
            moodLabel: $mood->moodLabel,
            moodScore: $mood->moodScore,
            confidence: $mood->confidence,
            reasons: $mood->reasons,
            confounders: $confounders,
            rawSignals: $mood->rawSignals,
            normalized: $mood->normalized,
        );
    }

    private function buildAiInput(MoodResult $mood, RepoSnapshot $snapshot): MoodAiInput
    {
        $rawSignals = [];
        if ($mood->rawSignals !== null) {
            foreach ($mood->rawSignals->all() as $key => $signal) {
                $rawSignals[$key] = $signal->value;
            }
        }

        $extensionHistogram = $this->buildExtensionHistogram($snapshot);
        $topPaths = $this->buildTopPaths($snapshot);

        return new MoodAiInput(
            windowLabel: $mood->windowLabel,
            moodLabel: $mood->moodLabel,
            moodScore: $mood->moodScore,
            confidence: $mood->confidence,
            rawSignals: $rawSignals,
            normalized: $mood->normalized,
            reasons: $mood->reasons,
            confounders: $mood->confounders,
            commitsCount: count($snapshot->commits),
            extensionHistogram: $extensionHistogram,
            topPaths: $topPaths,
        );
    }

    /**
     * @return array<string, int>
     */
    private function buildExtensionHistogram(RepoSnapshot $snapshot): array
    {
        $histogram = [];
        foreach ($snapshot->filesTouched as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION) ?: 'no_ext';
            $histogram[$ext] = ($histogram[$ext] ?? 0) + 1;
        }
        arsort($histogram);

        return array_slice($histogram, 0, 10, true);
    }

    /**
     * @return array<string>
     */
    private function buildTopPaths(RepoSnapshot $snapshot): array
    {
        return array_slice($snapshot->filesTouched, 0, 20);
    }
}
