<?php

namespace App\Services\Model\Providers;

/**
 * Muse Spark Open (open-weight) hosted on Baseten.
 *
 * Verified on 2026-10-28: accepts text, image_url parts, reasoning_effort and
 * json_schema response_format. Responses carry reasoning_content next to
 * content; only content is used. PDF "file" parts are rejected (HTTP 400),
 * so PDFs are rendered to page images first.
 */
class BasetenProvider extends OpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'baseten';
    }

    public function supportsPdf(): bool
    {
        return false;
    }

    /** Verified Oct 28: "HTTP 400: Too many images: 11 (max 8)". */
    public function maxImages(): int
    {
        return 8;
    }
}
