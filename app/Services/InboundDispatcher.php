<?php

namespace App\Services;

/**
 * Hands a stored inbound message to a background `php spark fonnte:process`,
 * so the webhook can answer Fonnte immediately (CLAUDE.md §4: no queue,
 * respond within 10 s, reply through the send API).
 */
class InboundDispatcher
{
    public function dispatch(int $messageId): void
    {
        // Under Apache, PHP_BINARY is not the CLI binary; PHP_CLI can point to it explicitly.
        $php = (string) env('PHP_CLI', str_ends_with(PHP_BINARY, 'php') ? PHP_BINARY : 'php');

        exec(sprintf(
            '%s %s fonnte:process %d > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(ROOTPATH . 'spark'),
            $messageId,
        ));
    }
}
