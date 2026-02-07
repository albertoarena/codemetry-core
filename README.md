# Codemetry Core

Framework-agnostic Git repository analysis pipeline that produces a **metrics-based "mood proxy"** (bad/medium/good) for each day or time window.

This package provides the core analysis engine. For Laravel integration, see [codemetry/laravel](https://github.com/albertoarena/codemetry-laravel).

**[Documentation](https://albertoarena.github.io/codemetry)** | **[Getting Started](https://albertoarena.github.io/codemetry/getting-started/installation/)**

## Requirements

- PHP 8.2+
- Git

## Installation

```bash
composer require codemetry/core
```

## Usage

```php
use Codemetry\Core\Analyzer;
use Codemetry\Core\Domain\AnalysisRequest;

$analyzer = new Analyzer();
$request = new AnalysisRequest(
    days: 7,
    branch: 'main',
);

$result = $analyzer->analyze('/path/to/repo', $request);

foreach ($result->windows as $mood) {
    echo "{$mood->windowLabel}: {$mood->moodLabel->value} ({$mood->moodScore}%)\n";
}
```

## Signal Providers

Built-in providers that generate metrics for each analysis window:

| Provider | Signals |
|---|---|
| **ChangeShape** | Additions, deletions, churn, commit count, files touched, churn per commit, scatter |
| **CommitMessage** | Fix/revert/wip keyword counts, fix ratio |
| **FollowUpFix** | Commits touching the same files within a configurable horizon, fix density |

## Extending

Add a custom signal provider by implementing the `SignalProvider` interface:

```php
use Codemetry\Core\Signals\SignalProvider;
use Codemetry\Core\Domain\RepoSnapshot;
use Codemetry\Core\Domain\SignalSet;
use Codemetry\Core\Signals\ProviderContext;

class MyProvider implements SignalProvider
{
    public function id(): string
    {
        return 'my_provider';
    }

    public function provide(RepoSnapshot $snapshot, ProviderContext $ctx): SignalSet
    {
        // Compute and return signals
    }
}
```

## Privacy

- All analysis runs locally via Git commands against your repository.
- No data is sent to external services unless AI engines are explicitly enabled.

## License

MIT
