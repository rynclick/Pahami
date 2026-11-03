<?php

namespace App\Services\Model;

/**
 * Normalized result of one chat completion, independent of provider.
 */
final class ModelResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?int $promptTokens,
        public readonly ?int $completionTokens,
        public readonly int $latencyMs,
        public readonly ?string $finishReason = null,
    ) {
    }
}
