<?php

declare(strict_types=1);

namespace Codemetry\Core\Baseline;

final class BaselineCache
{
    /**
     * @param array<string> $providerIds
     * @param array<string, mixed> $config
     */
    public function load(
        string $repoPath,
        int $baselineDays,
        array $providerIds,
        array $config,
    ): ?Baseline {
        $path = $this->resolvePath($repoPath);
        if ($path === null || !file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $expectedKey = $this->cacheKey($baselineDays, $providerIds, $config);
        if (($data['cache_key'] ?? null) !== $expectedKey) {
            return null;
        }

        return Baseline::fromArray($data['baseline']);
    }

    /**
     * @param array<string> $providerIds
     * @param array<string, mixed> $config
     */
    public function save(
        string $repoPath,
        Baseline $baseline,
        int $baselineDays,
        array $providerIds,
        array $config,
    ): void {
        $path = $this->resolvePath($repoPath);
        if ($path === null) {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return; // Cache is optional, fail gracefully
            }
        }

        $data = [
            'cache_key' => $this->cacheKey($baselineDays, $providerIds, $config),
            'cached_at' => (new \DateTimeImmutable())->format('c'),
            'baseline' => $baseline->toArray(),
        ];

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string> $providerIds
     * @param array<string, mixed> $config
     */
    private function cacheKey(int $baselineDays, array $providerIds, array $config): string
    {
        return md5(json_encode([
            'baseline_days' => $baselineDays,
            'providers' => $providerIds,
            'config' => $config,
        ], JSON_THROW_ON_ERROR));
    }

    private function resolvePath(string $repoPath): ?string
    {
        // Primary: <repoPath>/.git/codemetry/cache-baseline.json
        $gitDir = $repoPath . '/.git';
        if (is_dir($gitDir) && is_writable($gitDir)) {
            return $gitDir . '/codemetry/cache-baseline.json';
        }

        // Fallback: sys_get_temp_dir()/codemetry/<repoId>/cache-baseline.json
        $repoId = md5(realpath($repoPath) ?: $repoPath);
        $tempPath = sys_get_temp_dir() . '/codemetry/' . $repoId . '/cache-baseline.json';

        return $tempPath;
    }
}
