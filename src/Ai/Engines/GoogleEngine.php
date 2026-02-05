<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai\Engines;

/**
 * Google Generative AI engine (Gemini models)
 */
final class GoogleEngine extends AbstractAiEngine
{
    public function id(): string
    {
        return 'google';
    }

    protected function defaultModel(): string
    {
        return 'gemini-1.5-flash';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    /**
     * @return array<string, mixed>
     */
    protected function callApi(string $userPrompt): array
    {
        $baseUrl = $this->baseUrl ?? $this->defaultBaseUrl();
        $url = "{$baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => self::SYSTEM_PROMPT . "\n\n" . $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 1000,
                'responseMimeType' => 'application/json',
            ],
        ];

        $headers = [
            'Content-Type' => 'application/json',
        ];

        return $this->httpPost($url, $payload, $headers);
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function extractContent(array $response): string
    {
        $candidates = $response['candidates'] ?? [];
        if (empty($candidates)) {
            return '{}';
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        if (empty($parts)) {
            return '{}';
        }

        return (string) ($parts[0]['text'] ?? '{}');
    }
}
