<?php

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use App\Services\Fonnte\SimulatorClient;
use App\Services\WhatsAppFormatter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Fixtures;

/**
 * /demo/chat without model calls: greetings, commands and reminders need no
 * model. Document analysis and Q&A through the same handler are covered by
 * InboundHandlerTest and were checked live.
 *
 * @internal
 */
final class DemoChatTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private const PHONE = 'sim0123456789ab';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = '';
        // The handler is built with a client; these tests never reach the model.
        $_ENV['META_API_KEY'] = $_SERVER['META_API_KEY'] = 'test-key';
        $_ENV['MODEL_PROVIDER'] = $_SERVER['MODEL_PROVIDER'] = 'meta';
    }

    protected function tearDown(): void
    {
        // The stubbed security service must not leak into other test classes.
        Config\Services::resetSingle('security');
        parent::tearDown();
    }

    /** POST to an action; CSRF checking (CodeIgniter's own, tested code) is stubbed out. */
    private function postChat(string $action, array $fields = [], array $session = []): array
    {
        $security = new class (config('Security')) extends CodeIgniter\Security\Security {
            public function verify(CodeIgniter\HTTP\RequestInterface $request)
            {
                return $this;
            }
        };
        Config\Services::injectMock('security', $security);

        $result = $this->withSession($session)->post('demo/chat/' . $action, $fields);
        $result->assertOK();

        return json_decode($result->getJSON(), true);
    }

    private static function texts(array $state): array
    {
        return array_map(static fn (array $m) => html_entity_decode(strip_tags($m['html'])), $state['messages']);
    }

    public function testPageRenders(): void
    {
        $result = $this->get('demo/chat');

        $result->assertOK();
        $result->assertSee('Simulator WhatsApp');
        $result->assertSee('Kirim surat contoh (sintetis)');
    }

    public function testGreetingGetsTheIntroAsANewSimulatedUser(): void
    {
        $state = $this->postChat('send', ['text' => 'halo']);

        $this->assertNull($state['error']);
        $this->assertSame('halo', self::texts($state)[0]);
        $this->assertStringContainsString('Kirim foto, PDF, atau pesan suara', self::texts($state)[1]);
        $user = (new UserModel())->orderBy('id', 'DESC')->first();
        $this->assertTrue(SimulatorClient::isSimulated($user['phone']));
    }

    public function testEmptyMessageIsRefused(): void
    {
        $this->assertSame('Tulis pesan atau pilih file.', $this->postChat('send', ['text' => '  '])['error']);
    }

    public function testReminderButtonWithoutRemindersExplains(): void
    {
        $this->assertSame('Belum ada pengingat yang terjadwal.', $this->postChat('reminder')['error']);
    }

    public function testReminderButtonDeliversTheNextReminderIntoTheChat(): void
    {
        $userId = (new UserModel())->insert(['phone' => self::PHONE]);
        $docId  = (new DocumentModel())->insert([
            'user_id' => $userId, 'source' => 'whatsapp', 'status' => 'done', 'mime' => 'image/png',
            'nomor_perkara' => '123/Pdt.G/2099/PA.Btl', 'pengadilan_kode' => 'btl',
            'sidang_tanggal' => '2099-11-10', 'sidang_jam' => '09.00 WIB',
            'analysis_json' => ['analysis' => Fixtures::analysis()],
        ]);
        $reminders = new ReminderModel();
        foreach (['h3' => '2099-11-07 09:00:00', 'h1' => '2099-11-09 16:00:00'] as $kind => $at) {
            $reminders->insert(['document_id' => $docId, 'user_id' => $userId, 'kind' => $kind, 'send_at' => $at, 'status' => 'pending']);
        }

        $state = $this->postChat('reminder', [], ['sim_phone' => self::PHONE]);

        $this->assertNull($state['error']);
        $this->assertSame([['h3', 'sent'], ['h1', 'pending']], array_map(static fn ($r) => [$r['kind'], $r['status']], $state['reminders']));
        $this->assertStringStartsWith('📅 Pengingat: sidang perkara 123/Pdt.G/2099/PA.Btl', self::texts($state)[0]);
    }

    public function testResetStartsANewConversation(): void
    {
        $userId = (new UserModel())->insert(['phone' => self::PHONE]);
        (new MessageModel())->insert(['user_id' => $userId, 'direction' => 'in', 'type' => 'text', 'content' => 'halo']);

        $state = $this->postChat('reset', [], ['sim_phone' => self::PHONE]);

        $this->assertSame([], $state['messages']);
    }

    public function testCommandWithoutDocumentIsAnsweredWithoutAModel(): void
    {
        $state = $this->postChat('send', ['text' => 'jadwal']);

        $this->assertStringStartsWith('Belum ada jadwal sidang', self::texts($state)[1]);
        $this->assertSame([], $state['calls']);
        $this->assertNotSame(WhatsAppFormatter::GANGGUAN, self::texts($state)[1]);
    }
}
