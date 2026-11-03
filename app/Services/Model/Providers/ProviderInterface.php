<?php

namespace App\Services\Model\Providers;

use App\Services\Model\ModelResult;

interface ProviderInterface
{
    /** Short provider name stored in model_calls.provider, e.g. "meta". */
    public function name(): string;

    public function model(): string;

    /** Whether the endpoint accepts a PDF "file" content part; if not, pages are sent as images. */
    public function supportsPdf(): bool;

    /** Most images accepted in one request. */
    public function maxImages(): int;

    /**
     * Sends an OpenAI-style chat completion request.
     *
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     * @param array<string, mixed> $params Extra body fields (reasoning_effort, response_format, ...)
     */
    public function chat(array $messages, array $params = []): ModelResult;
}
