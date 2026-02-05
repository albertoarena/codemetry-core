<?php

use Codemetry\Core\Baseline\Baseline;
use Codemetry\Core\Baseline\BaselineCache;
use Codemetry\Core\Baseline\BaselineDistribution;

function createCacheTestDir(): string
{
    $dir = sys_get_temp_dir() . '/codemetry-cache-test-' . uniqid();
    mkdir($dir . '/.git', 0755, true);

    return $dir;
}

function cleanupCacheTestDir(string $dir): void
{
    (new Symfony\Component\Process\Process(['rm', '-rf', $dir]))->run();
}

test('saves and loads baseline from cache', function () {
    $dir = createCacheTestDir();

    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10, 20, 30]),
        'change.commits_count' => BaselineDistribution::fromValues([1, 2, 3]),
    ], 3);

    $cache = new BaselineCache();
    $providers = ['change_shape', 'commit_message'];
    $config = ['baseline_days' => 56];

    $cache->save($dir, $baseline, 56, $providers, $config);

    $loaded = $cache->load($dir, 56, $providers, $config);

    expect($loaded)->not->toBeNull()
        ->and($loaded->windowCount)->toBe(3)
        ->and($loaded->get('change.churn')->mean)->toBe($baseline->get('change.churn')->mean)
        ->and($loaded->get('change.commits_count')->mean)->toBe($baseline->get('change.commits_count')->mean);

    cleanupCacheTestDir($dir);
});

test('returns null when cache does not exist', function () {
    $dir = createCacheTestDir();

    $cache = new BaselineCache();
    $loaded = $cache->load($dir, 56, ['change_shape'], []);

    expect($loaded)->toBeNull();

    cleanupCacheTestDir($dir);
});

test('returns null when cache key mismatches', function () {
    $dir = createCacheTestDir();

    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10, 20]),
    ], 2);

    $cache = new BaselineCache();

    // Save with one config
    $cache->save($dir, $baseline, 56, ['change_shape'], ['key' => 'value1']);

    // Load with different config — cache key mismatch
    $loaded = $cache->load($dir, 56, ['change_shape'], ['key' => 'value2']);

    expect($loaded)->toBeNull();

    cleanupCacheTestDir($dir);
});

test('returns null when provider list changes', function () {
    $dir = createCacheTestDir();

    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10]),
    ], 1);

    $cache = new BaselineCache();

    // Save with one provider list
    $cache->save($dir, $baseline, 56, ['change_shape'], []);

    // Load with different provider list
    $loaded = $cache->load($dir, 56, ['change_shape', 'commit_message'], []);

    expect($loaded)->toBeNull();

    cleanupCacheTestDir($dir);
});

test('returns null when baseline days changes', function () {
    $dir = createCacheTestDir();

    $baseline = new Baseline([
        'change.churn' => BaselineDistribution::fromValues([10]),
    ], 1);

    $cache = new BaselineCache();

    $cache->save($dir, $baseline, 56, ['change_shape'], []);

    // Load with different baseline days
    $loaded = $cache->load($dir, 30, ['change_shape'], []);

    expect($loaded)->toBeNull();

    cleanupCacheTestDir($dir);
});

test('stores cache in .git/codemetry directory', function () {
    $dir = createCacheTestDir();

    $baseline = new Baseline([], 0);
    $cache = new BaselineCache();
    $cache->save($dir, $baseline, 56, [], []);

    $cachePath = $dir . '/.git/codemetry/cache-baseline.json';
    expect(file_exists($cachePath))->toBeTrue();

    $data = json_decode(file_get_contents($cachePath), true);
    expect($data)->toHaveKey('cache_key')
        ->and($data)->toHaveKey('cached_at')
        ->and($data)->toHaveKey('baseline');

    cleanupCacheTestDir($dir);
});
