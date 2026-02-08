<?php

declare(strict_types=1);

namespace Codemetry\Core\Ai\Engines;

use Codemetry\Core\Ai\AiEngine;
use Codemetry\Core\Ai\AiEngineException;
use Codemetry\Core\Ai\MoodAiInput;
use Codemetry\Core\Ai\MoodAiSummary;

/**
 * Base class for AI engines with common HTTP and prompt functionality.
 */
abstract class AbstractAiEngine implements AiEngine
{
    protected const SYSTEM_PROMPT = <<<'PROMPT'
You are a software metrics assistant. You do not infer emotions. You explain risk, quality, and strain signals from Git repository analysis.

Given the metrics below, provide:
1. Up to 5 bullet points explaining what the mood proxy result means
2. Any confounders or caveats to consider
3. Optionally, a score_delta (between -10 and +10) if the heuristic missed something important

Respond in JSON format with this structure:
{
  "explanation_bullets": ["bullet 1", "bullet 2", ...],
  "score_delta": 0,
  "confidence_delta": 0.0
}
PROMPT;

    protected const BATCH_SYSTEM_PROMPT = <<<'PROMPT'
You are a software metrics assistant. You do not infer emotions. You explain risk, quality, and strain signals from Git repository analysis.

You will receive metrics for MULTIPLE days. For EACH day (identified by window_label), provide:
1. Up to 5 bullet points explaining what the mood proxy result means
2. Any confounders or caveats to consider
3. Optionally, a score_delta (between -10 and +10) if the heuristic missed something important

Respond in JSON format with an object where keys are the window_label values:
{
  "2024-01-15": {
    "explanation_bullets": ["bullet 1", "bullet 2", ...],
    "score_delta": 0,
    "confidence_delta": 0.0
  },
  "2024-01-16": {
    "explanation_bullets": ["bullet 1", "bullet 2", ...],
    "score_delta": 0,
    "confidence_delta": 0.0
  }
}
PROMPT;

    protected string $apiKey;
    protected string $model;
    protected ?string $baseUrl;
    protected int $timeout;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->model = (string) ($config['model'] ?? $this->defaultModel());
        $this->baseUrl = isset($config['base_url']) ? (string) $config['base_url'] : null;
        $this->timeout = (int) ($config['timeout'] ?? 30);
    }

    abstract protected function defaultModel(): string;

    abstract protected function defaultBaseUrl(): string;

    public function summarize(MoodAiInput $input): MoodAiSummary
    {
        if ($this->apiKey === '') {
            throw AiEngineException::missingApiKey($this->id());
        }

        $userPrompt = $this->buildUserPrompt($input);
        $response = $this->callApi($userPrompt);

        return $this->parseResponse($response);
    }

    /**
     * @param array<MoodAiInput> $inputs
     * @return array<string, MoodAiSummary> Keyed by window_label
     */
    public function summarizeBatch(array $inputs): array
    {
        if ($this->apiKey === '') {
            throw AiEngineException::missingApiKey($this->id());
        }

        if (empty($inputs)) {
            return [];
        }

        // For a single input, use the regular method
        if (count($inputs) === 1) {
            $input = reset($inputs);
            return [$input->windowLabel => $this->summarize($input)];
        }

        $userPrompt = $this->buildBatchUserPrompt($inputs);
        $response = $this->callApiBatch($userPrompt);

        return $this->parseBatchResponse($response, $inputs);
    }

    protected function buildUserPrompt(MoodAiInput $input): string
    {
        return "Analyze these software metrics:\n\n" . json_encode($input, JSON_PRETTY_PRINT);
    }

    /**
     * @param array<MoodAiInput> $inputs
     */
    protected function buildBatchUserPrompt(array $inputs): string
    {
        $data = [];
        foreach ($inputs as $input) {
            $data[$input->windowLabel] = $input;
        }

        return "Analyze these software metrics for multiple days:\n\n" . json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function callApi(string $userPrompt): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function callApiBatch(string $userPrompt): array;

    /**
     * @param array<string, mixed> $response
     */
    protected function parseResponse(array $response): MoodAiSummary
    {
        $content = $this->extractContent($response);

        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw AiEngineException::invalidResponse($this->id(), 'Response is not valid JSON');
        }

        return MoodAiSummary::fromArray($json);
    }

    /**
     * Parse batch response from AI API.
     *
     * @param array<string, mixed> $response
     * @param array<MoodAiInput> $inputs
     * @return array<string, MoodAiSummary>
     */
    protected function parseBatchResponse(array $response, array $inputs): array
    {
        $content = $this->extractContent($response);

        $json = json_decode($content, true);
        if (!is_array($json)) {
            throw AiEngineException::invalidResponse($this->id(), 'Batch response is not valid JSON');
        }

        $results = [];
        foreach ($inputs as $input) {
            $label = $input->windowLabel;
            if (isset($json[$label]) && is_array($json[$label])) {
                $results[$label] = MoodAiSummary::fromArray($json[$label]);
            } else {
                // Fallback: empty summary for missing entries
                $results[$label] = new MoodAiSummary();
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $response
     */
    abstract protected function extractContent(array $response): string;

    /**
     * Extract a human-readable error message from an API error response.
     */
    protected function extractErrorMessage(string $response, int $httpCode): string
    {
        $decoded = json_decode($response, true);

        if (is_array($decoded)) {
            // OpenAI/DeepSeek format: {"error": {"message": "..."}}
            if (isset($decoded['error']['message'])) {
                return "HTTP {$httpCode}: " . $decoded['error']['message'];
            }
            // Anthropic format: {"error": {"message": "..."}} or {"message": "..."}
            if (isset($decoded['message'])) {
                return "HTTP {$httpCode}: " . $decoded['message'];
            }
            // Google format: {"error": {"message": "..."}}
            if (isset($decoded['error']) && is_string($decoded['error'])) {
                return "HTTP {$httpCode}: " . $decoded['error'];
            }
        }

        // Fallback to raw response (truncated)
        $truncated = strlen($response) > 200 ? substr($response, 0, 200) . '...' : $response;
        return "HTTP {$httpCode}: {$truncated}";
    }

    /**
     * Make an HTTP POST request.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function httpPost(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw AiEngineException::requestFailed($this->id(), 'Failed to initialize cURL');
        }

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw AiEngineException::requestFailed($this->id(), 'Failed to encode request payload');
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw AiEngineException::requestFailed($this->id(), $error ?: 'Unknown cURL error');
        }

        if ($httpCode >= 400) {
            $errorMessage = $this->extractErrorMessage((string) $response, $httpCode);
            throw AiEngineException::requestFailed($this->id(), $errorMessage);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw AiEngineException::invalidResponse($this->id(), 'Response is not valid JSON');
        }

        return $decoded;
    }
}
