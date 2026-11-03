<?php

namespace App\Services;

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use App\Services\Fonnte\FonnteClient;
use App\Services\Model\ModelException;
use App\Services\Model\Transcriber;
use DateTimeImmutable;
use DateTimeZone;
use finfo;
use RuntimeException;
use Throwable;

/**
 * Processes one stored inbound WhatsApp message and sends the replies
 * (CLAUDE.md §7). Runs in the background, after the webhook has answered.
 *
 * - photo / PDF → "Sedang saya baca…", analysis, reminders, explanation + checklist + follow-up
 * - voice note → Muse Voice Transcribe, then handled exactly like the typed text
 * - commands: checklist, jadwal, stop, pengadilan, ringkasan, bantuan (case-insensitive)
 * - "ya" or a date → confirms an uncertain hearing date, then schedules reminders
 * - greetings → intro; any other text → ConversationAgent (follow-up Q&A on the latest document)
 */
class InboundHandler
{
    private const UPLOAD_DIR = 'uploads/whatsapp/';

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    private const COMMANDS = [
        'checklist'  => ['checklist', 'cek list', 'ceklist', 'daftar dokumen'],
        'jadwal'     => ['jadwal', 'jadwal sidang'],
        'stop'       => ['stop', 'berhenti', 'stop pengingat'],
        'pengadilan' => ['pengadilan', 'alamat', 'kontak', 'alamat pengadilan'],
        'ringkasan'  => ['ringkasan', 'ringkasan surat'],
        'bantuan'    => ['bantuan', 'help', 'menu', 'info'],
        'ya'         => ['ya', 'iya', 'y', 'benar', 'betul', 'ya benar', 'iya benar'],
        'halo'       => ['', 'halo', 'hallo', 'hai', 'hi', 'hello', 'assalamualaikum', 'assalamu alaikum', 'selamat pagi', 'selamat siang', 'selamat sore', 'selamat malam', 'pagi', 'p', 'mulai', 'start'],
    ];

    /** An uncertain hearing date can be confirmed this long after the document came in. */
    private const CONFIRM_WINDOW_DAYS = 7;

    public function __construct(
        private readonly FonnteClient $fonnte,
        private readonly DocumentAnalyzer $analyzer,
        private readonly ConversationAgent $agent,
        private readonly ?Transcriber $transcriber = null,
        private readonly WhatsAppFormatter $formatter = new WhatsAppFormatter(),
        private readonly ReminderPlanner $planner = new ReminderPlanner(),
        private readonly MessageModel $messages = new MessageModel(),
        private readonly DocumentModel $documents = new DocumentModel(),
        private readonly UserModel $users = new UserModel(),
        private readonly ReminderModel $reminders = new ReminderModel(),
    ) {
    }

    public function handle(int $messageId): void
    {
        $message = $this->messages->find($messageId);
        if ($message === null || $message['direction'] !== 'in') {
            throw new RuntimeException("Inbound message {$messageId} not found.");
        }

        $userId = (int) $message['user_id'];
        $phone  = $this->users->find($userId)['phone'];

        try {
            match ($message['type']) {
                'image', 'pdf' => $this->handleDocument($message, $phone),
                'audio'        => $this->handleVoice($message, $phone),
                default        => $this->handleText($userId, $phone, (string) $message['content'], $messageId),
            };
        } catch (Throwable $e) {
            log_message('error', "Inbound message {$messageId} failed: {$e->getMessage()}");
            $this->reply($userId, $phone, WhatsAppFormatter::GANGGUAN);
        }
    }

    private function handleDocument(array $message, string $phone): void
    {
        $userId = (int) $message['user_id'];
        $this->reply($userId, $phone, WhatsAppFormatter::READING);

        $bytes = $this->fonnte->download((string) $message['media_url']);
        // Trust the bytes, not the file name.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! isset(self::EXTENSIONS[$mime])) {
            $this->reply($userId, $phone, WhatsAppFormatter::FILE_TIDAK_DIDUKUNG);

            return;
        }

        $relative = self::UPLOAD_DIR . bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
        if (! is_dir(WRITEPATH . self::UPLOAD_DIR)) {
            // World-writable like the rest of writable/: the web worker and CLI may run as different users.
            mkdir(WRITEPATH . self::UPLOAD_DIR, 0777, true);
            chmod(WRITEPATH . self::UPLOAD_DIR, 0777);
        }
        if (file_put_contents(WRITEPATH . $relative, $bytes) === false) {
            throw new RuntimeException('Could not store the uploaded file in ' . self::UPLOAD_DIR);
        }

        $documentId = (int) $this->documents->insert([
            'user_id'   => $userId,
            'source'    => 'whatsapp',
            'file_path' => $relative,
            'mime'      => $mime,
            'status'    => 'processing',
        ]);
        $this->messages->update($message['id'], ['document_id' => $documentId]);

        $result = $this->analyzer->analyze($bytes, $mime, $message['media_filename'] ?? 'dokumen', $documentId, $this->now());
        $this->documents->update($documentId, $result->documentFields());

        if ($result->ok) {
            // Reminders only for a verified hearing date; the reply promises exactly what was scheduled.
            $kinds   = $this->planner->schedule($this->documents->find($documentId), $this->now());
            $replies = $this->formatter->documentReply($result->analysis, $result->verification, $kinds);
        } else {
            // Only say "re-photo" when the document is the problem, not the model.
            $replies = [$result->failure === AnalysisResult::FAILED_MODEL ? WhatsAppFormatter::GANGGUAN : WhatsAppFormatter::UNREADABLE];
        }

        $this->replyAll($userId, $phone, $replies, $documentId);
    }

    /** Transcribes, shows the user what was heard, then treats it as typed text. */
    private function handleVoice(array $message, string $phone): void
    {
        $userId = (int) $message['user_id'];

        try {
            if ($this->transcriber === null) {
                throw new ModelException('No transcriber configured.');
            }
            $transcript = $this->transcriber->transcribe($this->fonnte->download((string) $message['media_url']));
        } catch (ModelException|RuntimeException $e) {
            log_message('error', "Voice note {$message['id']} failed: {$e->getMessage()}");
            $this->reply($userId, $phone, WhatsAppFormatter::AUDIO_GAGAL);

            return;
        }

        $this->messages->update($message['id'], ['transcript' => $transcript]);

        if ($transcript === '') {
            $this->reply($userId, $phone, WhatsAppFormatter::AUDIO_KOSONG);

            return;
        }

        // Transparency: the user sees what Pahami understood before the answer.
        $this->reply($userId, $phone, "🎙️ _Saya dengar:_ \"{$transcript}\"");
        $this->handleText($userId, $phone, $transcript, (int) $message['id']);
    }

    private function handleText(int $userId, string $phone, string $text, int $messageId): void
    {
        $command = self::command($text);
        $latest  = $this->latestDocument($userId);

        $reply = match ($command) {
            'halo'       => WhatsAppFormatter::INTRO,
            'bantuan'    => $this->formatter->help(),
            'checklist'  => $latest !== null
                ? $this->formatter->checklistOnly($latest['analysis_json']['analysis'])
                : 'Belum ada surat yang Anda kirim. Kirim foto atau PDF surat dari pengadilan, lalu saya buatkan daftar dokumennya.',
            'jadwal'     => $this->formatter->schedule($this->latestWithHearing($userId), $this->pendingReminders($userId)),
            'stop'       => $this->formatter->stopped($this->planner->cancelAll($userId)),
            'pengadilan' => $this->formatter->court($latest['pengadilan_kode'] ?? null),
            'ringkasan'  => $latest !== null
                ? $this->formatter->deskSummary($latest, $this->lastEscalatedQuestion($userId))
                : 'Belum ada surat yang Anda kirim. Kirim foto atau PDF surat dari pengadilan terlebih dahulu.',
            default      => $this->tryConfirmSchedule($userId, $command, $text),
        };

        if ($reply !== null) {
            $this->reply($userId, $phone, $reply, $latest['id'] ?? null);

            return;
        }

        $answer = $this->agent->answer($userId, $text, $messageId, $this->now());
        $this->reply($userId, $phone, $answer['text'], $answer['document_id'], $answer['escalated']);
    }

    /** The question that was handed to the PTSP desk most recently, for the desk summary. */
    private function lastEscalatedQuestion(int $userId): ?string
    {
        $answer = $this->messages->where(['user_id' => $userId, 'direction' => 'out', 'escalated' => 1])->orderBy('id', 'DESC')->first();
        if ($answer === null) {
            return null;
        }

        $question = $this->messages->where(['user_id' => $userId, 'direction' => 'in'])->where('id <', $answer['id'])->orderBy('id', 'DESC')->first();

        return $question === null ? null : mb_substr((string) ($question['transcript'] ?? $question['content']), 0, 300);
    }

    /**
     * "ya" confirms the date the model read; a message containing a date
     * replaces it. Only while a document is waiting for confirmation.
     */
    private function tryConfirmSchedule(int $userId, ?string $command, string $text): ?string
    {
        $document = $this->awaitingConfirmation($userId);
        if ($document === null) {
            return $command === 'ya' ? 'Tidak ada jadwal yang perlu dikonfirmasi.' : null;
        }

        $sidang = $document['analysis_json']['analysis']['sidang'];

        if ($command === 'ya') {
            $tanggal = self::validDate($sidang['tanggal']);
            $jam     = self::parseJam((string) $sidang['jam']);
            if ($tanggal === null) {
                return 'Tanggal di surat tidak bisa saya pastikan. Mohon kirim tanggal sidang yang benar, contoh: *12 November 2026 jam 09.00*.';
            }
        } else {
            $tanggal = Verifier::parseTanggal($text);
            if ($tanggal === null) {
                return null;
            }
            $jam = self::parseJam($text);
        }

        $json                 = $document['analysis_json'];
        $json['konfirmasi']   = ['oleh' => 'pengguna', 'pada' => $this->now()->format('Y-m-d H:i:s'), 'tanggal' => $tanggal, 'jam' => $jam, 'teks' => mb_substr($text, 0, 200)];
        $this->documents->update($document['id'], ['sidang_tanggal' => $tanggal, 'sidang_jam' => $jam, 'analysis_json' => $json]);

        $document = $this->documents->find($document['id']);
        $kinds    = $this->planner->schedule($document, $this->now());

        return $this->formatter->scheduleConfirmed($document, $kinds);
    }

    private function latestDocument(int $userId): ?array
    {
        return $this->documents->where(['user_id' => $userId, 'status' => 'done'])->orderBy('id', 'DESC')->first();
    }

    private function latestWithHearing(int $userId): ?array
    {
        return $this->documents->where(['user_id' => $userId, 'status' => 'done'])
            ->where('sidang_tanggal IS NOT NULL')->orderBy('id', 'DESC')->first();
    }

    private function pendingReminders(int $userId): array
    {
        return $this->reminders->where(['user_id' => $userId, 'status' => 'pending'])->orderBy('send_at')->findAll();
    }

    /** The latest document, if its hearing date is still waiting for the user's confirmation. */
    private function awaitingConfirmation(int $userId): ?array
    {
        $document = $this->latestDocument($userId);
        if ($document === null || $document['sidang_tanggal'] !== null) {
            return null;
        }

        $json = $document['analysis_json'];
        if (empty($json['verification']['perlu_konfirmasi_jadwal']) || isset($json['konfirmasi'])) {
            return null;
        }

        $age = $this->now()->getTimestamp() - strtotime($document['created_at']);

        return $age <= self::CONFIRM_WINDOW_DAYS * 86400 ? $document : null;
    }

    /** Normalized command name, or null for free text. */
    private static function command(string $text): ?string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($text))));

        foreach (self::COMMANDS as $command => $words) {
            if (in_array($normalized, $words, true)) {
                return $command;
            }
        }

        return null;
    }

    private static function validDate(?string $ymd): ?string
    {
        if ($ymd === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $ymd;
    }

    /** "jam 9.00", "09:00 WIB" → "09.00 WIB"; null when no time is given. */
    private static function parseJam(string $text): ?string
    {
        // Not part of a date such as 12.11.2026.
        if (! preg_match('/(?<![\d\/.-])([01]?\d|2[0-3])[.:]([0-5]\d)(?![\d\/.-])/', $text, $m)) {
            return null;
        }

        return sprintf('%02d.%02d WIB', $m[1], $m[2]);
    }

    private function replyAll(int $userId, string $phone, array $texts, ?int $documentId): void
    {
        foreach ($texts as $i => $text) {
            if ($i > 0) {
                $this->pause();
            }
            $this->reply($userId, $phone, $text, $documentId);
        }
    }

    /** Sends one message and records it. A failed send is logged, not retried. */
    private function reply(int $userId, string $phone, string $text, ?int $documentId = null, bool $escalated = false): void
    {
        $fonnteId = null;

        try {
            $fonnteId = $this->fonnte->sendText($phone, $text);
        } catch (RuntimeException $e) {
            log_message('error', "Fonnte send to user {$userId} failed: {$e->getMessage()}");
        }

        $this->messages->insert([
            'user_id'           => $userId,
            'document_id'       => $documentId,
            'direction'         => 'out',
            'type'              => 'text',
            'content'           => $text,
            'escalated'         => $escalated ? 1 : 0,
            'fonnte_message_id' => $fonnteId,
        ]);
    }

    /** Overridden in tests. */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    }

    /** Keeps the messages in order on the user's phone. Overridden in tests. */
    protected function pause(): void
    {
        sleep(1);
    }
}
