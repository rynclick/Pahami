<?php

namespace App\Services\Model\Providers;

use App\Services\Model\ModelException;
use App\Services\Model\ModelResult;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;

/**
 * Shared HTTP logic for providers that speak the OpenAI chat completions format.
 *
 * Retries once on 429/500/503, honoring Retry-After. 504 is not retried: per
 * Meta's error docs the same request will most likely time out again.
 */
abstract class OpenAiCompatibleProvider implements ProviderInterface
{
    private const RETRYABLE = [429, 500, 502, 503];

    /** Upper bound on Retry-After so a webhook-triggered call cannot hang for minutes. */
    private const MAX_RETRY_WAIT_SECONDS = 10;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly string $model,
        protected readonly int $timeoutSeconds = 60,
    ) {
        if ($apiKey === '') {
            throw new ModelException("API key for provider '{$this->name()}' is not set in .env.", null, $this->name());
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $params = []): ModelResult
    {
        $body = array_merge(['model' => $this->model, 'messages' => $messages], $params);

        $started  = hrtime(true);
        $response = $this->post($body);

        if (in_array($response['status'], self::RETRYABLE, true)) {
            $this->pause($this->retryDelay($response['retry_after']));
            $response = $this->post($body);
        }

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($response['status'] !== 200) {
            throw new ModelException(
                "{$this->name()} returned HTTP {$response['status']}: " . $this->errorMessage($response['body']),
                $response['status'],
                $this->name(),
            );
        }

        return $this->parse($response['body'], $latencyMs);
    }

    /**
     * Performs the HTTP POST. Overridden in tests.
     *
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: string, retry_after: string}
     */
    protected function post(array $body): array
    {
        $client = Services::curlrequest([], null, null, false);

        try {
            $response = $client->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'        => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'timeout'     => $this->timeoutSeconds,
                'http_errors' => false,
            ]);
        } catch (HTTPException $e) {
            // Network failure or timeout: no HTTP status to retry on.
            throw new ModelException("{$this->name()} request failed: {$e->getMessage()}", null, $this->name());
        }

        return [
            'status'      => $response->getStatusCode(),
            'body'        => (string) $response->getBody(),
            'retry_after' => $response->getHeaderLine('Retry-After'),
        ];
    }

    /** Sleeps between attempts. Overridden in tests. */
    protected function pause(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    private function retryDelay(string $retryAfter): float
    {
        if (is_numeric($retryAfter)) {
            return min((float) $retryAfter, self::MAX_RETRY_WAIT_SECONDS);
        }

        // No header: exponential backoff start (1s) plus jitter.
        return 1 + mt_rand(0, 1000) / 1000;
    }

    private function parse(string $raw, int $latencyMs): ModelResult
    {
        $data    = json_decode($raw, true);
        $choice  = $data['choices'][0] ?? null;
        $content = $choice['message']['content'] ?? null;
        $finish  = $choice['finish_reason'] ?? null;

        // reasoning_content (Baseten) is deliberately ignored.
        if (! is_string($content) || trim($content) === '') {
            $hint = $finish === 'length' ? ' (token limit reached during reasoning; raise max_completion_tokens)' : '';

            throw new ModelException("{$this->name()} returned no content{$hint}.", 200, $this->name());
        }

        return new ModelResult(
            content: $content,
            provider: $this->name(),
            model: $data['model'] ?? $this->model,
            promptTokens: $data['usage']['prompt_tokens'] ?? null,
            completionTokens: $data['usage']['completion_tokens'] ?? null,
            latencyMs: $latencyMs,
            finishReason: $finish,
        );
    }

    private function errorMessage(string $raw): string
    {
        $data = json_decode($raw, true);

        return $data['error']['message'] ?? mb_substr($raw, 0, 200) ?: '(empty body)';
    }
}
