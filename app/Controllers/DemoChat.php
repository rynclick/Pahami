<?php

namespace App\Controllers;

use App\Models\MessageModel;
use App\Models\ModelCallModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use App\Services\ConversationAgent;
use App\Services\DocumentAnalyzer;
use App\Services\Fonnte\SimulatorClient;
use App\Services\InboundHandler;
use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use App\Services\Model\Transcriber;
use App\Services\ReminderSender;
use App\Services\SyntheticSummons;
use App\Services\Translator;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use finfo;

/**
 * WhatsApp simulator (/demo/chat): the full agent without a Fonnte account.
 *
 * Messages go through the same InboundHandler as real WhatsApp messages
 * (analysis, reminders, commands, follow-up Q&A, voice notes); only the
 * transport differs (SimulatorClient). Each browser session is one
 * simulated user. The side panel shows the agent's actions: the reminders
 * it scheduled and every model call it made.
 */
class DemoChat extends BaseController
{
    private const MAX_BYTES = DocumentAnalyzer::MAX_BYTES;

    private const MEDIA_TYPES = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'application/pdf' => ['pdf', 'pdf'],
        'audio/ogg'  => ['audio', 'ogg'], 'audio/mpeg' => ['audio', 'mp3'], 'audio/wav' => ['audio', 'wav'],
        'audio/x-wav' => ['audio', 'wav'], 'audio/mp4' => ['audio', 'm4a'], 'audio/x-m4a' => ['audio', 'm4a'],
        'audio/aac'  => ['audio', 'aac'], 'audio/webm' => ['audio', 'webm'], 'video/webm' => ['audio', 'webm'],
        // A browser recording in Ogg may be detected as video.
        'video/ogg'  => ['audio', 'ogg'],
    ];

    protected $helpers = ['url', 'whatsapp'];

    public function index(): string
    {
        return view('demo/chat', [
            'title'           => 'Simulator WhatsApp · Pahami',
            'defaultProvider' => (string) env('MODEL_PROVIDER', 'meta'),
            'provider'        => (string) session('sim_provider'),
            'state'           => $this->state(),
        ]);
    }

    /** GET /demo/chat/state */
    public function poll(): ResponseInterface
    {
        return $this->json();
    }

    /** Everything the page shows; also returned by every action. */
    private function state(): array
    {
        $userId = $this->userId(false);

        $messages = $reminders = [];
        if ($userId !== null) {
            foreach ((new MessageModel())->where('user_id', $userId)->orderBy('id')->findAll() as $m) {
                $messages[] = [
                    'id'        => (int) $m['id'],
                    'translate' => site_url('demo/chat/translate/' . $m['id']),
                    'out'       => $m['direction'] === 'out',
                    'html'      => wa_to_html(self::bubbleText($m)),
                    'escalated' => (bool) $m['escalated'],
                    'time'      => substr((string) $m['created_at'], 11, 5),
                ];
            }
            foreach ((new ReminderModel())->where('user_id', $userId)->orderBy('send_at')->findAll() as $r) {
                $reminders[] = ['kind' => $r['kind'], 'send_at' => substr((string) $r['send_at'], 0, 16), 'status' => $r['status']];
            }
        }

        $ids   = (array) session('sim_calls');
        $calls = $ids === [] ? [] : (new ModelCallModel())->whereIn('id', $ids)->orderBy('id', 'DESC')->findAll(30);

        return [
            'messages'  => $messages,
            'reminders' => $reminders,
            'calls'     => array_map(static fn (array $c) => [
                'purpose'    => $c['purpose'],
                'model'      => $c['provider'] . ' · ' . $c['model'],
                'tokens'     => $c['prompt_tokens'] !== null ? "{$c['prompt_tokens']} → {$c['completion_tokens']}" : '—',
                'latency'    => $c['latency_ms'] !== null ? number_format($c['latency_ms'] / 1000, 1) . ' s' : '—',
                'error'      => $c['error'],
                'time'       => substr((string) $c['created_at'], 11, 8),
            ], $calls),
        ];
    }

    /** GET /demo/chat/translate/{id}: English translation of one message of this session (English mode). */
    public function translate(int $messageId): ResponseInterface
    {
        $userId  = $this->userId(false);
        $message = $userId === null ? null : (new MessageModel())->where('user_id', $userId)->find($messageId);
        if ($message === null) {
            return $this->response->setStatusCode(404)->setJSON(['html' => '']);
        }

        return self::translated(self::bubbleText($message), $this->response);
    }

    /** Shared by /demo and /demo/chat. */
    public static function translated(string $text, ResponseInterface $response): ResponseInterface
    {
        try {
            $english = (new Translator(ModelClient::fromEnv()))->toEnglish($text);
        } catch (ModelException $e) {
            return $response->setStatusCode(503)->setJSON(['html' => esc($e->getMessage())]);
        }

        helper('whatsapp');

        return $response->setJSON(['html' => wa_to_html($english)]);
    }

    /** A typed message, an uploaded file (photo, PDF, audio) or a browser voice recording. */
    public function send(): ResponseInterface
    {
        $text = trim((string) $this->request->getPost('text'));
        $file = $this->request->getFile('file');
        $this->rememberProvider();

        if ($file !== null && $file->isValid()) {
            if ($file->getSize() > self::MAX_BYTES) {
                return $this->json('File terlalu besar (maksimal 20 MB).');
            }
            $bytes = (string) file_get_contents($file->getTempName());
            $mime  = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
            // Unknown types go through the document path, which explains what it can read.
            [$type, $ext] = self::MEDIA_TYPES[$mime] ?? ['image', 'bin'];

            return $this->process($this->storeInbound($type, $text, $bytes, $ext, $file->getClientName()));
        }

        if ($text === '') {
            return $this->json('Tulis pesan atau pilih file.');
        }

        return $this->process($this->storeInbound('text', $text));
    }

    /** Sends a freshly drawn synthetic summons with a hearing 10 days ahead (fictional parties). */
    public function sample(): ResponseInterface
    {
        $this->rememberProvider();
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
        $png   = SyntheticSummons::png(SyntheticSummons::hearingFrom($today), $today);

        return $this->process($this->storeInbound('image', '', $png, 'png', 'relaas_contoh_sintetis.png'));
    }

    /** Delivers the next pending reminder now, as the cron would at its time. */
    public function reminder(): ResponseInterface
    {
        $userId = $this->userId(false);
        $next   = $userId === null ? null
            : (new ReminderModel())->where(['user_id' => $userId, 'status' => 'pending'])->orderBy('send_at')->first();

        if ($next === null) {
            return $this->json('Belum ada pengingat yang terjadwal.');
        }

        $at = new DateTimeImmutable($next['send_at'], new DateTimeZone('Asia/Jakarta'));
        (new ReminderSender(new SimulatorClient()))->send($next, $at);

        return $this->json();
    }

    /** Starts over as a new simulated user. */
    public function reset(): ResponseInterface
    {
        session()->remove(['sim_phone', 'sim_calls']);

        return $this->json();
    }

    private function process(int $messageId): ResponseInterface
    {
        // A long PDF with a retry can take a few minutes.
        set_time_limit(300);
        $calls  = new ModelCallModel();
        $before = (int) ($calls->selectMax('id')->first()['id'] ?? 0);

        try {
            $handler = $this->handler();
        } catch (ModelException $e) {
            return $this->json('Model belum disiapkan: ' . $e->getMessage());
        }
        $handler->handle($messageId);

        $new = array_column($calls->select('id')->where('id >', $before)->findAll(), 'id');
        session()->set('sim_calls', array_slice(array_merge((array) session('sim_calls'), $new), -50));

        return $this->json();
    }

    private function handler(): InboundHandler
    {
        $provider = (string) session('sim_provider');
        $client   = $provider !== '' ? new ModelClient(ModelClient::makeProvider($provider)) : ModelClient::fromEnv();

        try {
            $transcriber = Transcriber::fromEnv();
        } catch (ModelException) {
            $transcriber = null;
        }

        return new class (new SimulatorClient(), new DocumentAnalyzer($client), new ConversationAgent($client), $transcriber) extends InboundHandler {
            // No WhatsApp delivery to pace: the page shows all replies at once.
            protected function pause(): void
            {
            }
        };
    }

    private function storeInbound(string $type, string $text, ?string $bytes = null, string $ext = '', ?string $filename = null): int
    {
        $url = null;
        if ($bytes !== null) {
            $dir = WRITEPATH . SimulatorClient::INBOX;
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
                chmod($dir, 0777);
            }
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            file_put_contents($dir . $name, $bytes);
            $url = SimulatorClient::URL_SCHEME . $name;
        }

        return (int) (new MessageModel())->insert([
            'user_id'        => $this->userId(true),
            'direction'      => 'in',
            'type'           => $type,
            'content'        => $text !== '' ? $text : null,
            'media_url'      => $url,
            'media_filename' => $filename,
        ]);
    }

    /** The simulated user of this browser session; created on the first message. */
    private function userId(bool $create): ?int
    {
        $users = new UserModel();
        $phone = (string) session('sim_phone');
        $user  = $phone !== '' ? $users->where('phone', $phone)->first() : null;

        if ($user !== null) {
            return (int) $user['id'];
        }
        if (! $create) {
            return null;
        }

        $phone = SimulatorClient::PHONE_PREFIX . bin2hex(random_bytes(6));
        session()->set('sim_phone', $phone);

        return (int) $users->insert(['phone' => $phone, 'name' => 'Simulator']);
    }

    private function rememberProvider(): void
    {
        $provider = (string) $this->request->getPost('provider');
        session()->set('sim_provider', in_array($provider, ['meta', 'baseten'], true) ? $provider : '');
    }

    /** What a bubble shows: the text, or a label for a photo, PDF or voice note. */
    private static function bubbleText(array $message): string
    {
        $label = match ($message['type']) {
            'image' => '🖼️ ' . ($message['media_filename'] ?? 'Foto'),
            'pdf'   => '📄 ' . ($message['media_filename'] ?? 'PDF'),
            'audio' => '🎙️ Pesan suara',
            default => null,
        };

        if ($label === null) {
            return (string) $message['content'];
        }

        return trim($label . "\n" . ($message['content'] ?? '') . ($message['transcript'] ? "\n_\"{$message['transcript']}\"_" : ''));
    }

    private function json(?string $error = null): ResponseInterface
    {
        return $this->response->setJSON(['error' => $error, 'csrf' => csrf_hash()] + $this->state());
    }
}
