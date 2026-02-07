<?php

declare(strict_types=1);

namespace Codemetry\Core\Signals\Providers;

use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\Signal;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Domain\SignalType;
use Codemetry\Core\Signals\ProviderContext;
use Codemetry\Core\Signals\SignalProvider;

final class CommitMessageProvider implements SignalProvider
{
    private const FIX_PATTERN = '/\b(fix|bug|hotfix|patch|typo|oops)\b/i';
    private const REVERT_PATTERN = '/\b(revert)\b/i';
    private const WIP_PATTERN = '/\b(wip|tmp|debug|hack)\b/i';

    public function id(): string
    {
        return 'commit_message';
    }

    public function provide(RepoSnapshot $snapshot, ProviderContext $ctx): SignalSet
    {
        $fixCount = 0;
        $revertCount = 0;
        $wipCount = 0;

        $fixPattern = $this->validatePattern(
            $ctx->config['keywords']['fix_pattern'] ?? self::FIX_PATTERN,
            self::FIX_PATTERN
        );
        $revertPattern = $this->validatePattern(
            $ctx->config['keywords']['revert_pattern'] ?? self::REVERT_PATTERN,
            self::REVERT_PATTERN
        );
        $wipPattern = $this->validatePattern(
            $ctx->config['keywords']['wip_pattern'] ?? self::WIP_PATTERN,
            self::WIP_PATTERN
        );

        foreach ($snapshot->commits as $commit) {
            $subject = $commit->subject;

            if (preg_match($fixPattern, $subject)) {
                $fixCount++;
            }
            if (preg_match($revertPattern, $subject)) {
                $revertCount++;
            }
            if (preg_match($wipPattern, $subject)) {
                $wipCount++;
            }
        }

        $commitsCount = $snapshot->commitsCount;
        $fixRatio = $commitsCount > 0
            ? round($fixCount / $commitsCount, 4)
            : 0.0;

        return new SignalSet($snapshot->window->label, [
            'msg.fix_keyword_count' => new Signal(
                'msg.fix_keyword_count',
                SignalType::Numeric,
                $fixCount,
                'Commits matching fix keywords',
            ),
            'msg.revert_count' => new Signal(
                'msg.revert_count',
                SignalType::Numeric,
                $revertCount,
                'Commits matching revert keyword',
            ),
            'msg.wip_count' => new Signal(
                'msg.wip_count',
                SignalType::Numeric,
                $wipCount,
                'Commits matching WIP keywords',
            ),
            'msg.fix_ratio' => new Signal(
                'msg.fix_ratio',
                SignalType::Numeric,
                $fixRatio,
                'Ratio of fix commits to total commits',
            ),
        ]);
    }

    /**
     * Validate a regex pattern and fall back to default if invalid.
     */
    private function validatePattern(string $pattern, string $default): string
    {
        if (@preg_match($pattern, '') === false) {
            return $default;
        }

        return $pattern;
    }
}
