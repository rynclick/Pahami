<?php

namespace App\Services\Model\Providers;

/**
 * Muse Spark via the Meta Model API (https://api.meta.ai/v1).
 */
class MetaProvider extends OpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'meta';
    }

    public function supportsPdf(): bool
    {
        return true;
    }

    /** Meta docs: 50 images per request. */
    public function maxImages(): int
    {
        return 50;
    }
}
