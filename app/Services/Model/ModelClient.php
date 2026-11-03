<?php

namespace App\Services\Model;

use App\Models\ModelCallModel;
use App\Services\Model\Providers\BasetenProvider;
use App\Services\Model\Providers\MetaProvider;
use App\Services\Model\Providers\ProviderInterface;

/**
 * Provider-agnostic chat completion (text, image, PDF).
 *
 * MODEL_PROVIDER picks the provider for every call. MODEL_VISION_PROVIDER,
 * when set, overrides it for calls that contain an image or file part.
 * Every call, successful or not, is logged to model_calls.
 */
class ModelClient
{
    /** Generous default: Muse Spark spends part of the budget on reasoning. */
    private const DEFAULT_MAX_COMPLETION_TOKENS = 8000;

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly ?ProviderInterface $visionProvider = null,
        private readonly ModelCallModel $calls = new ModelCallModel(),
    ) {
    }

    public static function fromEnv(): self
    {
        $text   = (string) env('MODEL_PROVIDER', 'meta');
        $vision = (string) env('MODEL_VISION_PROVIDER', '');

        return new self(
            self::makeProvider($text),
            $vision !== '' && $vision !== $text ? self::makeProvider($vision) : null,
        );
    }

    public static function makeProvider(string $name): ProviderInterface
    {
        $timeout = (int) env('MODEL_TIMEOUT', 120);

        return match ($name) {
            'meta' => new MetaProvider(
                (string) env('META_API_KEY', ''),
                (string) env('META_BASE_URL', 'https://api.meta.ai/v1'),
                (string) env('META_MODEL', 'muse-spark-1.3'),
                $timeout,
            ),
            'baseten' => new BasetenProvider(
                (string) env('BASETEN_API_KEY', ''),
                (string) env('BASETEN_BASE_URL', 'https://inference.baseten.co/v1'),
                (string) env('BASETEN_MODEL', 'meta-models/muse-spark-open-1026'),
                $timeout,
            ),
            default => throw new ModelException("Unknown model provider '{$name}'. Use meta or baseten."),
        };
    }

    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     * @param string               $purpose    Stored in model_calls.purpose, e.g. "document_analysis"
     * @param array<string, mixed> $params     Extra request fields (reasoning_effort, response_format, ...)
     */
    public function chat(array $messages, string $purpose, array $params = [], ?int $documentId = null): ModelResult
    {
        $provider = $this->providerFor($messages);
        $params += ['max_completion_tokens' => self::DEFAULT_MAX_COMPLETION_TOKENS];
        $started  = hrtime(true);

        try {
            $result = $provider->chat($messages, $params);
        } catch (ModelException $e) {
            $this->log($provider->name(), $provider->model(), $purpose, $documentId, [
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error'      => mb_substr($e->getMessage(), 0, 255),
            ]);

            throw $e;
        }

        $this->log($result->provider, $result->model, $purpose, $documentId, [
            'prompt_tokens'     => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'latency_ms'        => $result->latencyMs,
        ]);

        return $result;
    }

    /** The provider that will receive image/PDF calls. */
    public function mediaProvider(): ProviderInterface
    {
        return $this->visionProvider ?? $this->provider;
    }

    public function providerFor(array $messages): ProviderInterface
    {
        return self::hasMedia($messages) ? $this->mediaProvider() : $this->provider;
    }

    public static function textPart(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** Inline image as a base64 data URI (Meta docs: image_url part, max 50 MB). */
    public static function imagePart(string $bytes, string $mime): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64," . base64_encode($bytes)]];
    }

    /** Inline PDF as a file part (Meta docs: file.file_data data URI, first 50 pages rendered). */
    public static function pdfPart(string $bytes, string $filename): array
    {
        return ['type' => 'file', 'file' => [
            'filename'  => $filename,
            'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes),
        ]];
    }

    private static function hasMedia(array $messages): bool
    {
        foreach ($messages as $message) {
            if (! is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $part) {
                if (in_array($part['type'] ?? '', ['image_url', 'file'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function log(string $provider, string $model, string $purpose, ?int $documentId, array $fields): void
    {
        $this->calls->insert(array_merge([
            'provider'    => $provider,
            'model'       => $model,
            'purpose'     => $purpose,
            'document_id' => $documentId,
        ], $fields));
    }
}
