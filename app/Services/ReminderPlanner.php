<?php

namespace App\Services;

use App\Models\ReminderModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Hearing date → reminder rows (CLAUDE.md §3.4): H-3, H-1 and the morning of
 * the hearing. Only verified dates are planned (documents.sidang_tanggal is
 * set only when the Verifier passed or the user confirmed), and only
 * reminders still in the future.
 */
class ReminderPlanner
{
    /** kind => [days before the hearing, local send time] */
    public const SCHEDULE = [
        'h3' => [3, '09:00'],
        'h1' => [1, '16:00'],
        'h0' => [0, '06:00'],
    ];

    public function __construct(private readonly ReminderModel $reminders = new ReminderModel())
    {
    }

    /**
     * Pure planning step, also used by /demo to show what would be scheduled.
     *
     * @return list<array{kind: string, send_at: string}> In send order
     */
    public static function plan(?string $sidangTanggal, DateTimeImmutable $now): array
    {
        if ($sidangTanggal === null) {
            return [];
        }

        $tz      = new DateTimeZone('Asia/Jakarta');
        $hearing = DateTimeImmutable::createFromFormat('!Y-m-d', $sidangTanggal, $tz);
        if ($hearing === false) {
            return [];
        }

        $plan = [];
        foreach (self::SCHEDULE as $kind => [$daysBefore, $time]) {
            [$h, $m] = array_map('intval', explode(':', $time));
            $sendAt  = $hearing->modify("-{$daysBefore} days")->setTime($h, $m);

            if ($sendAt > $now->setTimezone($tz)) {
                $plan[] = ['kind' => $kind, 'send_at' => $sendAt->format('Y-m-d H:i:s')];
            }
        }

        return $plan;
    }

    /**
     * Replaces the pending reminders of this document, and of earlier
     * documents of the same user and case number (a newer summons wins).
     *
     * @param array $document Row from documents
     *
     * @return list<string> Kinds scheduled
     */
    public function schedule(array $document, DateTimeImmutable $now): array
    {
        if ($document['user_id'] === null) {
            return [];
        }

        $this->cancelFor((int) $document['user_id'], (int) $document['id'], $document['nomor_perkara']);

        $kinds = [];
        foreach (self::plan($document['sidang_tanggal'], $now) as $row) {
            $this->reminders->insert([
                'document_id' => $document['id'],
                'user_id'     => $document['user_id'],
                'send_at'     => $row['send_at'],
                'kind'        => $row['kind'],
                'status'      => 'pending',
            ]);
            $kinds[] = $row['kind'];
        }

        return $kinds;
    }

    /** Cancels all pending reminders of a user ("stop"). Returns how many. */
    public function cancelAll(int $userId): int
    {
        $count = $this->reminders->where(['user_id' => $userId, 'status' => 'pending'])->countAllResults();
        $this->reminders->where(['user_id' => $userId, 'status' => 'pending'])->set(['status' => 'cancelled'])->update();

        return $count;
    }

    private function cancelFor(int $userId, int $documentId, ?string $nomorPerkara): void
    {
        $builder = $this->reminders->builder()
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->groupStart()->where('document_id', $documentId);

        if ($nomorPerkara !== null) {
            $builder->orWhereIn('document_id', static function ($sub) use ($userId, $nomorPerkara) {
                return $sub->select('id')->from('documents')->where('user_id', $userId)->where('nomor_perkara', $nomorPerkara);
            });
        }

        $builder->groupEnd()->set(['status' => 'cancelled'])->update();
    }
}
