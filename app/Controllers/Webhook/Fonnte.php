<?php

namespace App\Controllers\Webhook;

use App\Controllers\BaseController;
use App\Models\MessageModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbound WhatsApp messages from Fonnte (https://docs.fonnte.com/webhook-reply-message/).
 *
 * Stores the message, dispatches processing to the background and answers
 * at once. Fonnte does not sign webhooks, so the URL carries a secret:
 * /webhook/fonnte?key=FONNTE_WEBHOOK_SECRET
 */
class Fonnte extends BaseController
{
    private const AUDIO = ['ogg', 'opus', 'oga', 'mp3', 'm4a', 'aac', 'wav', 'amr'];

    public function receive(): ResponseInterface
    {
        $secret = (string) env('FONNTE_WEBHOOK_SECRET', '');
        if ($secret === '') {
            return $this->answer(503, 'webhook secret not configured');
        }
        if (! hash_equals($secret, (string) $this->request->getGet('key'))) {
            return $this->answer(403, 'forbidden');
        }

        $payload = $this->payload();
        $sender  = preg_replace('/\D/', '', (string) ($payload['sender'] ?? ''));

        // Groups, status updates and other non-chat events are ignored.
        if ($sender === '' || str_contains((string) ($payload['sender'] ?? ''), '@g.us') || ! empty($payload['member'])) {
            return $this->answer(200, 'ignored');
        }

        $messages = new MessageModel();
        $inboxId  = isset($payload['inboxid']) && $payload['inboxid'] !== '' ? (string) $payload['inboxid'] : null;
        if ($inboxId !== null && $messages->where(['direction' => 'in', 'fonnte_message_id' => $inboxId])->countAllResults() > 0) {
            return $this->answer(200, 'duplicate');
        }

        $userId = $this->upsertUser($sender, $payload['name'] ?? null);
        $url    = trim((string) ($payload['url'] ?? ''));

        $messageId = $messages->insert([
            'user_id'           => $userId,
            'direction'         => 'in',
            'type'              => self::typeOf($url, (string) ($payload['extension'] ?? ''), (string) ($payload['filename'] ?? '')),
            'content'           => (string) ($payload['message'] ?? ''),
            'media_url'         => $url !== '' ? $url : null,
            'media_filename'    => $payload['filename'] ?? null,
            'fonnte_message_id' => $inboxId,
        ]);

        service('inboundDispatcher')->dispatch((int) $messageId);

        return $this->answer(200, 'queued');
    }

    /**
     * Fonnte posts JSON; fall back to form fields, then query parameters
     * (a GET without a sender, e.g. Fonnte checking the URL, is simply ignored).
     */
    private function payload(): array
    {
        try {
            $json = $this->request->getJSON(true);
        } catch (HTTPException) {
            $json = null;
        }

        if (is_array($json)) {
            return $json;
        }

        $post = $this->request->getPost();

        return $post !== [] ? $post : array_diff_key($this->request->getGet(), ['key' => true]);
    }

    private function upsertUser(string $phone, mixed $name): int
    {
        $users = new UserModel();
        $user  = $users->where('phone', $phone)->first();
        $now   = date('Y-m-d H:i:s');

        if ($user === null) {
            return (int) $users->insert([
                'phone'        => $phone,
                'name'         => is_string($name) && $name !== '' ? mb_substr($name, 0, 100) : null,
                'last_seen_at' => $now,
            ]);
        }

        $users->update($user['id'], ['last_seen_at' => $now]);

        return (int) $user['id'];
    }

    /** messages.type from the attachment; anything else is handled as text. */
    private static function typeOf(string $url, string $extension, string $filename): string
    {
        if ($url === '') {
            return 'text';
        }

        $ext = strtolower(ltrim($extension !== '' ? $extension : pathinfo($filename, PATHINFO_EXTENSION), '.'));

        return match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png'], true) => 'image',
            $ext === 'pdf'                              => 'pdf',
            in_array($ext, self::AUDIO, true)           => 'audio',
            // Unknown files go through the document path, which checks the real type and explains.
            default => 'image',
        };
    }

    private function answer(int $status, string $detail): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['status' => $status === 200, 'detail' => $detail]);
    }
}
