<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai\Engines;

/**
 * Anthropic API engine (Claude models)
 */
final class AnthropicEngine extends AbstractAiEngine
{
    public function id(): string
    {
        return 'anthropic';
    }

    protected function defaultModel(): string
    {
        return 'claude-sonnet-4-20250514';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    /**
     * @return array<string, mixed>
     */
    protected function callApi(string $userPrompt): array
    {
        $url = ($this->baseUrl ?? $this->defaultBaseUrl()) . '/messages';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 1000,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        return $this->httpPost($url, $payload, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function callApiBatch(string $userPrompt): array
    {
        $url = ($this->baseUrl ?? $this->defaultBaseUrl()) . '/messages';

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4000,
            'system' => self::BATCH_SYSTEM_PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        return $this->httpPost($url, $payload, $headers);
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function extractContent(array $response): string
    {
        $content = $response['content'] ?? [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return (string) ($block['text'] ?? '{}');
            }
        }

        return '{}';
    }
}
