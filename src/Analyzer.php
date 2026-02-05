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
        $aiUnavailable = false;

        foreach ($windows as $window) {
            $snapshot = $this->gitReader->buildSnapshot($repoPath, $window, $request->author, $request->branch);
            $collected = $this->registry->collect($snapshot, $ctx);

            $features = $this->normalizer->normalize($collected['signals'], $baseline);
            $confounders = $collected['confounders'];

            // Add ai_unavailable confounder if AI was requested but not available
            if ($aiUnavailable && !in_array('ai_unavailable', $confounders, true)) {
                $confounders[] = 'ai_unavailable';
            }

            $mood = $this->scorer->score($features, $confounders);

            // Apply AI enhancement if available
            if ($aiEngine !== null && !$aiUnavailable) {
                $mood = $this->applyAiEnhancement($aiEngine, $mood, $snapshot, $aiUnavailable);
            }

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

    private function applyAiEnhancement(
        AiEngine $engine,
        MoodResult $mood,
        RepoSnapshot $snapshot,
        bool &$aiUnavailable,
    ): MoodResult {
        try {
            $input = $this->buildAiInput($mood, $snapshot);
            $summary = $engine->summarize($input);

            return $mood->withAiSummary($summary);
        } catch (AiEngineException) {
            $aiUnavailable = true;

            // Add ai_unavailable confounder
            $confounders = $mood->confounders;
            if (!in_array('ai_unavailable', $confounders, true)) {
                $confounders[] = 'ai_unavailable';
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
