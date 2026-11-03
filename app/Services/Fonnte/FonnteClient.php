<?php

namespace App\Services\Fonnte;

use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;
use RuntimeException;

/**
 * Fonnte WhatsApp API (https://docs.fonnte.com/api-send-message/).
 *
 * The token goes in the Authorization header as-is (no "Bearer"); the send
 * endpoint takes form fields and answers {"status": true, "id": [...]} or
 * {"status": false, "reason": "..."}.
 */
class FonnteClient
{
    private const SEND_URL = 'https://api.fonnte.com/send';

    /** Same limit as DocumentAnalyzer::MAX_BYTES. */
    private const MAX_DOWNLOAD_BYTES = 20_000_000;

    public function __construct(private readonly string $token)
    {
    }

    public static function fromEnv(): self
    {
        return new self((string) env('FONNTE_TOKEN', ''));
    }

    /**
     * Sends a text message.
     *
     * @return string|null Fonnte message id
     *
     * @throws RuntimeException When Fonnte refuses the message
     */
    public function sendText(string $target, string $message): ?string
    {
        if ($this->token === '') {
            throw new RuntimeException('FONNTE_TOKEN is not set in .env.');
        }

        $data = json_decode($this->post(self::SEND_URL, [
            'target'      => $target,
            'message'     => $message,
            'countryCode' => '62',
        ]), true);

        // Fonnte's docs show both "status" and "Status" on failures.
        $status = $data['status'] ?? $data['Status'] ?? false;
        if ($status !== true) {
            throw new RuntimeException('Fonnte send failed: ' . ($data['reason'] ?? 'unknown response'));
        }

        return isset($data['id'][0]) ? (string) $data['id'][0] : null;
    }

    /**
     * Downloads an inbound attachment from the URL in the webhook payload.
     */
    public function download(string $url): string
    {
        if (! preg_match('~^https://~i', $url)) {
            throw new RuntimeException('Refusing to download a non-HTTPS media URL.');
        }

        try {
            $response = Services::curlrequest([], null, null, false)->get($url, [
                'timeout'     => 30,
                'http_errors' => false,
            ]);
        } catch (HTTPException $e) {
            throw new RuntimeException('Media download failed: ' . $e->getMessage());
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || $body === '') {
            throw new RuntimeException("Media download failed: HTTP {$response->getStatusCode()}");
        }
        if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            throw new RuntimeException('Media file is larger than 20 MB.');
        }

        return $body;
    }

    /** Overridden in tests. */
    protected function post(string $url, array $fields): string
    {
        try {
            $response = Services::curlrequest([], null, null, false)->post($url, [
                'headers'     => ['Authorization' => $this->token],
                'form_params' => $fields,
                'timeout'     => 30,
                'http_errors' => false,
            ]);
        } catch (HTTPException $e) {
            throw new RuntimeException('Fonnte request failed: ' . $e->getMessage());
        }

        return (string) $response->getBody();
    }
}
