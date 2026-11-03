<?php

use App\Models\DocumentModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use App\Services\Fonnte\FonnteClient;
use App\Services\ReminderPlanner;
use App\Services\ReminderSender;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Fixtures;

/**
 * ReminderPlanner (date → rows) and ReminderSender (cron).
 *
 * @internal
 */
final class ReminderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private static function at(string $datetime): DateTimeImmutable
    {
        return new DateTimeImmutable($datetime, new DateTimeZone('Asia/Jakarta'));
    }

    private function document(array $overrides = []): array
    {
        $userId = $overrides['user_id'] ?? (new UserModel())->insert(['phone' => '6281200000001']);
        unset($overrides['user_id']);

        $id = (new DocumentModel())->insert(array_merge([
            'user_id'         => $userId,
            'source'          => 'whatsapp',
            'status'          => 'done',
            'nomor_perkara'   => '123/Pdt.G/2026/PA.Btl',
            'pengadilan_kode' => 'btl',
            'sidang_tanggal'  => '2026-11-10',
            'sidang_jam'      => '09.00 WIB',
            'analysis_json'   => ['analysis' => Fixtures::analysis()],
        ], $overrides));

        return (new DocumentModel())->find($id);
    }

    public function testPlanHasThreeRemindersBeforeAFarHearing(): void
    {
        $this->assertSame([
            ['kind' => 'h3', 'send_at' => '2026-11-07 09:00:00'],
            ['kind' => 'h1', 'send_at' => '2026-11-09 16:00:00'],
            ['kind' => 'h0', 'send_at' => '2026-11-10 06:00:00'],
        ], ReminderPlanner::plan('2026-11-10', self::at('2026-10-29 08:00')));
    }

    public function testPlanSkipsReminderTimesAlreadyPassed(): void
    {
        $this->assertSame(['h1', 'h0'], array_column(ReminderPlanner::plan('2026-11-10', self::at('2026-11-08 10:00')), 'kind'));
        $this->assertSame([], ReminderPlanner::plan('2026-11-10', self::at('2026-11-10 07:00')));
        $this->assertSame([], ReminderPlanner::plan(null, self::at('2026-10-29 08:00')));
    }

    public function testScheduleStoresPendingRows(): void
    {
        $kinds = (new ReminderPlanner())->schedule($this->document(), self::at('2026-10-29 08:00'));

        $this->assertSame(['h3', 'h1', 'h0'], $kinds);
        $this->seeNumRecords(3, 'reminders', ['status' => 'pending']);
    }

    public function testNewSummonsForSameCaseReplacesOldReminders(): void
    {
        $planner = new ReminderPlanner();
        $first   = $this->document();
        $planner->schedule($first, self::at('2026-10-29 08:00'));

        $second = $this->document(['user_id' => $first['user_id'], 'sidang_tanggal' => '2026-11-24']);
        $planner->schedule($second, self::at('2026-10-29 08:00'));

        $this->seeNumRecords(3, 'reminders', ['status' => 'cancelled', 'document_id' => $first['id']]);
        $this->seeNumRecords(3, 'reminders', ['status' => 'pending', 'document_id' => $second['id']]);
    }

    public function testStopCancelsEverythingPending(): void
    {
        $planner  = new ReminderPlanner();
        $document = $this->document();
        $planner->schedule($document, self::at('2026-10-29 08:00'));

        $this->assertSame(3, $planner->cancelAll((int) $document['user_id']));
        $this->seeNumRecords(0, 'reminders', ['status' => 'pending']);
    }

    public function testWebDocumentsGetNoReminders(): void
    {
        $id = (new DocumentModel())->insert(['source' => 'web', 'status' => 'done', 'sidang_tanggal' => '2026-11-10']);

        $this->assertSame([], (new ReminderPlanner())->schedule((new DocumentModel())->find($id), self::at('2026-10-29 08:00')));
    }

    private function fonnte(bool $fail = false): FonnteClient
    {
        return new class ($fail) extends FonnteClient {
            public array $sent = [];

            public function __construct(private bool $fail)
            {
                parent::__construct('test');
            }

            public function sendText(string $target, string $message): ?string
            {
                if ($this->fail) {
                    throw new RuntimeException('Fonnte send failed: insufficient quota');
                }
                $this->sent[] = [$target, $message];

                return 'f-' . count($this->sent);
            }
        };
    }

    public function testSenderSendsOnlyDueReminders(): void
    {
        $document = $this->document();
        (new ReminderPlanner())->schedule($document, self::at('2026-10-29 08:00'));
        $fonnte = $this->fonnte();

        $stats = (new ReminderSender($fonnte))->sendDue(self::at('2026-11-07 09:02'));

        $this->assertSame(['sent' => 1, 'failed' => 0, 'cancelled' => 0], $stats);
        $this->assertSame('6281200000001', $fonnte->sent[0][0]);
        $this->assertStringStartsWith('📅 Pengingat: sidang perkara 123/Pdt.G/2026/PA.Btl', $fonnte->sent[0][1]);
        $this->seeInDatabase('reminders', ['kind' => 'h3', 'status' => 'sent', 'sent_at' => '2026-11-07 09:02:00']);
        $this->seeInDatabase('messages', ['direction' => 'out', 'document_id' => $document['id'], 'fonnte_message_id' => 'f-1']);

        // Running again in the same window sends nothing twice.
        $this->assertSame(0, (new ReminderSender($fonnte))->sendDue(self::at('2026-11-07 09:07'))['sent']);
    }

    public function testMissedRemindersAfterTheHearingAreCancelled(): void
    {
        (new ReminderPlanner())->schedule($this->document(), self::at('2026-10-29 08:00'));
        $fonnte = $this->fonnte();

        $stats = (new ReminderSender($fonnte))->sendDue(self::at('2026-11-10 10:00'));

        $this->assertSame(['sent' => 0, 'failed' => 0, 'cancelled' => 3], $stats);
        $this->assertSame([], $fonnte->sent);
    }

    public function testFailedSendStaysPendingForTheNextRun(): void
    {
        (new ReminderPlanner())->schedule($this->document(), self::at('2026-10-29 08:00'));

        $stats = (new ReminderSender($this->fonnte(fail: true)))->sendDue(self::at('2026-11-07 09:02'));

        $this->assertSame(1, $stats['failed']);
        $this->seeInDatabase('reminders', ['kind' => 'h3', 'status' => 'pending']);
    }

    public function testSimulatorUsersGetTheirReminderInTheWebChatNotOnWhatsApp(): void
    {
        $userId = (new UserModel())->insert(['phone' => 'sim0123456789ab']);
        (new ReminderPlanner())->schedule($this->document(['user_id' => $userId]), self::at('2026-10-29 08:00'));
        $fonnte = $this->fonnte(fail: true);

        $stats = (new ReminderSender($fonnte))->sendDue(self::at('2026-11-07 09:02'));

        $this->assertSame(['sent' => 1, 'failed' => 0, 'cancelled' => 0], $stats);
        $this->assertSame([], $fonnte->sent);
        $this->seeInDatabase('messages', ['user_id' => $userId, 'direction' => 'out', 'fonnte_message_id' => null]);
    }
}
