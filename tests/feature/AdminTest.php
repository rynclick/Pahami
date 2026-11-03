<?php

use App\Controllers\Admin;
use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\UserModel;
use App\Services\Verifier;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Fixtures;

/**
 * /admin keeps real WhatsApp users, simulator sessions and web uploads apart.
 *
 * @internal
 */
final class AdminTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = '';

        $users = new UserModel();
        $real  = $users->insert(['phone' => '6281200001304']);
        $sim   = $users->insert(['phone' => 'sim0123456789ab']);

        $this->ids = [
            'whatsapp'  => $this->document('whatsapp', $real, '111/Pdt.G/2099/PA.Btl'),
            'simulator' => $this->document('whatsapp', $sim, '222/Pdt.G/2099/PA.Btl'),
            'web'       => $this->document('web', null, '333/Pdt.G/2099/PA.Btl'),
        ];

        $messages = new MessageModel();
        foreach ([$real => 'Apakah saya akan menang? (asli)', $sim => 'Apakah saya akan menang? (simulator)'] as $userId => $question) {
            $messages->insert(['user_id' => $userId, 'direction' => 'in', 'type' => 'text', 'content' => $question]);
            $messages->insert(['user_id' => $userId, 'direction' => 'out', 'type' => 'text', 'content' => 'Pertanyaan ini perlu dijawab petugas.', 'escalated' => 1]);
        }
    }

    private function document(string $source, ?int $userId, string $nomor): int
    {
        return (new DocumentModel())->insert([
            'user_id' => $userId, 'source' => $source, 'status' => 'done', 'mime' => 'image/png',
            'jenis_dokumen' => 'relaas_panggilan', 'jenis_perkara' => 'cerai_gugat', 'nomor_perkara' => $nomor,
            'analysis_json' => ['analysis' => Fixtures::analysis(), 'verification' => (new Verifier())->verify(Fixtures::analysis(), new DateTimeImmutable('2026-10-28'))],
        ]);
    }

    public function testRealWhatsAppUsersAreTheDefaultAndShownApart(): void
    {
        $result = $this->get('admin');

        $result->assertOK();
        $result->assertSee('111/Pdt.G/2099/PA.Btl');
        $result->assertDontSee('222/Pdt.G/2099/PA.Btl');
        $result->assertDontSee('333/Pdt.G/2099/PA.Btl');
        $result->assertSee('Apakah saya akan menang? (asli)');
        $result->assertDontSee('(simulator)');
        // Masked number only.
        $result->assertSee('62812•••1304');
        $result->assertDontSee('6281200001304');
        $result->assertSee('WhatsApp (pengguna asli) · 1');
        $result->assertSee('Simulator · 1');
        $result->assertSee('Unggah web · 1');
        $result->assertSee('Semua · 3');
    }

    public function testSimulatorTab(): void
    {
        $result = $this->get('admin', ['source' => 'simulator']);

        $result->assertSee('222/Pdt.G/2099/PA.Btl');
        $result->assertDontSee('111/Pdt.G/2099/PA.Btl');
        $result->assertSee('Apakah saya akan menang? (simulator)');
        $result->assertSee('sim-89ab');
    }

    public function testWebTabHasNoConversations(): void
    {
        $result = $this->get('admin', ['source' => 'web']);

        $result->assertSee('333/Pdt.G/2099/PA.Btl');
        $result->assertDontSee('Apakah saya akan menang?');
    }

    public function testDocumentDetailShowsTheConversation(): void
    {
        $result = $this->get('admin/document/' . $this->ids['whatsapp']);

        $result->assertOK();
        $result->assertSee('Apakah saya akan menang? (asli)');
        $result->assertSee('62812•••1304');
    }

    public function testUnknownDocumentIsNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->get('admin/document/999999');
    }

    public function testPurgedFileIsGone(): void
    {
        (new DocumentModel())->update($this->ids['web'], ['file_path' => 'uploads/demo/x.png', 'media_purged_at' => '2026-11-01 10:00:00']);

        $this->expectException(PageNotFoundException::class);

        $this->get('admin/document/' . $this->ids['web'] . '/file');
    }

    public function testLoginIsRequiredWhenPasswordIsSet(): void
    {
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = 'rahasia';

        $this->get('admin')->assertStatus(401);
    }

    public function testPhoneMasking(): void
    {
        $this->assertSame('62812•••1304', Admin::maskPhone('6281234561304'));
        $this->assertSame('sim-89ab', Admin::maskPhone('sim0123456789ab'));
        $this->assertSame('—', Admin::maskPhone(null));
    }
}
