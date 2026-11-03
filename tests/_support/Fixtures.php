<?php

namespace Tests\Support;

/**
 * Loads synthetic fixtures from /fixtures (fictional parties only).
 */
final class Fixtures
{
    public static function analysis(string $name = 'relaas_sintetis'): array
    {
        return json_decode((string) file_get_contents(ROOTPATH . "fixtures/analysis/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }
}
