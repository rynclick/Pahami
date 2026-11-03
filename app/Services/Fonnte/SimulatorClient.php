<?php

namespace App\Services\Fonnte;

use RuntimeException;

/**
 * Stands in for Fonnte in the web chat simulator (/demo/chat): replies are
 * only stored in `messages` (the page shows them), and "downloads" read the
 * file the visitor uploaded. Everything else is the real WhatsApp code path.
 */
class SimulatorClient extends FonnteClient
{
    /** Simulator users get phone "sim" + 12 hex chars; never a real number. */
    public const PHONE_PREFIX = 'sim';

    /** Uploads of the simulator, under writable/. */
    public const INBOX = 'uploads/simulator/';

    public const URL_SCHEME = 'sim://';

    public function __construct(private readonly string $root = WRITEPATH)
    {
        parent::__construct('');
    }

    public static function isSimulated(string $phone): bool
    {
        return str_starts_with($phone, self::PHONE_PREFIX);
    }

    public function sendText(string $target, string $message): ?string
    {
        return null;
    }

    /** @param string $url "sim://<file name in the inbox>" */
    public function download(string $url): string
    {
        $name = substr($url, strlen(self::URL_SCHEME));
        if (! str_starts_with($url, self::URL_SCHEME) || ! preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name)) {
            throw new RuntimeException('Not a simulator upload.');
        }

        $path = $this->root . self::INBOX . $name;
        if (! is_file($path)) {
            throw new RuntimeException('Simulator upload is gone (purged?).');
        }

        return (string) file_get_contents($path);
    }
}
