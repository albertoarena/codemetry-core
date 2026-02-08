<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai\Engines;

/**
 * DeepSeek API engine (DeepSeek Coder, DeepSeek Chat)
 *
 * DeepSeek uses an OpenAI-compatible API format.
 */
final class DeepSeekEngine extends AbstractAiEngine
{
    public function id(): string
    {
        return 'deepseek';
    }

    protected function defaultModel(): string
    {
        return 'deepseek-chat';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.deepseek.com/v1';
    }

    /**
     * @return array<string, mixed>
     */
    protected function callApi(string $userPrompt): array
    {
        $url = ($this->baseUrl ?? $this->defaultBaseUrl()) . '/chat/completions';

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 1000,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$this->apiKey}",
        ];

        return $this->httpPost($url, $payload, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function callApiBatch(string $userPrompt): array
    {
        $url = ($this->baseUrl ?? $this->defaultBaseUrl()) . '/chat/completions';

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => self::BATCH_SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 4000,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$this->apiKey}",
        ];

        return $this->httpPost($url, $payload, $headers);
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function extractContent(array $response): string
    {
        return (string) ($response['choices'][0]['message']['content'] ?? '{}');
    }
}
