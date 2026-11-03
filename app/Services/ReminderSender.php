<?php

namespace App\Services;

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use App\Services\Fonnte\FonnteClient;
use App\Services\Fonnte\SimulatorClient;
use DateTimeImmutable;
use RuntimeException;

/**
 * Sends the reminders that are due (run by `php spark reminders:send`).
 *
 * A reminder whose hearing has already started (e.g. the machine was off) is
 * cancelled instead of sent. A failed send stays pending and is retried on
 * the next run.
 */
class ReminderSender
{
    /** Per run, so one slow run cannot pile up behind the next. */
    private const BATCH = 50;

    public function __construct(
        private readonly FonnteClient $fonnte,
        private readonly WhatsAppFormatter $formatter = new WhatsAppFormatter(),
        private readonly ReminderModel $reminders = new ReminderModel(),
        private readonly DocumentModel $documents = new DocumentModel(),
        private readonly UserModel $users = new UserModel(),
        private readonly MessageModel $messages = new MessageModel(),
    ) {
    }

    /**
     * @return array{sent: int, failed: int, cancelled: int}
     */
    public function sendDue(DateTimeImmutable $now): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'cancelled' => 0];
        $due   = $this->reminders->where('status', 'pending')
            ->where('send_at <=', $now->format('Y-m-d H:i:s'))
            ->orderBy('send_at')
            ->findAll(self::BATCH);

        foreach ($due as $reminder) {
            $stats[$this->send($reminder, $now)]++;
        }

        return $stats;
    }

    /**
     * Sends one reminder (also used by the chat simulator to show a reminder ahead of time).
     *
     * @return string sent | failed | cancelled
     */
    public function send(array $reminder, DateTimeImmutable $now): string
    {
        $document = $this->documents->find($reminder['document_id']);

        if ($document === null || $document['sidang_tanggal'] === null || $this->hearingStarted($document, $now)) {
            $this->reminders->update($reminder['id'], ['status' => 'cancelled']);

            return 'cancelled';
        }

        $analysis = $document['analysis_json']['analysis'] ?? [];
        $text     = $this->formatter->reminder($document, $analysis, $reminder['kind']);
        $phone    = $this->users->find($reminder['user_id'])['phone'];
        // Simulator users (/demo/chat) see the reminder in the web chat instead of WhatsApp.
        $client = SimulatorClient::isSimulated($phone) ? new SimulatorClient() : $this->fonnte;

        try {
            $fonnteId = $client->sendText($phone, $text);
        } catch (RuntimeException $e) {
            log_message('error', "Reminder {$reminder['id']} failed: {$e->getMessage()}");

            return 'failed';
        }

        $this->reminders->update($reminder['id'], ['status' => 'sent', 'sent_at' => $now->format('Y-m-d H:i:s')]);
        $this->messages->insert([
            'user_id'           => $reminder['user_id'],
            'document_id'       => $document['id'],
            'direction'         => 'out',
            'type'              => 'text',
            'content'           => $text,
            'fonnte_message_id' => $fonnteId,
        ]);

        return 'sent';
    }

    /** The hearing time, or noon when only the date is known. */
    private function hearingStarted(array $document, DateTimeImmutable $now): bool
    {
        $time = '12:00';
        if ($document['sidang_jam'] !== null && preg_match('/(\d{1,2})[.:](\d{2})/', $document['sidang_jam'], $m)) {
            $time = sprintf('%02d:%02d', $m[1], $m[2]);
        }

        return $now->format('Y-m-d H:i') >= "{$document['sidang_tanggal']} {$time}";
    }
}
