<?php

use App\Models\DocumentModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Fixtures;

/**
 * Pages only; the upload itself calls a real model and is checked manually.
 *
 * @internal
 */
final class DemoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        // Pages are tested without the login; DemoAuth has its own tests below.
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = '';
    }

    public function testLoginIsRequiredWhenPasswordIsSet(): void
    {
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = 'rahasia';

        $this->get('demo')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('pahami:salah')])->get('demo')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('pahami:rahasia')])->get('demo')->assertOK();
    }

    private function storeAnalyzed(string $source = 'web', ?int $userId = null): int
    {
        return (new DocumentModel())->insert([
            'user_id'       => $userId,
            'source'        => $source,
            'mime'          => 'image/jpeg',
            'status'        => 'done',
            'nomor_perkara' => '123/Pdt.G/2026/PA.Btl',
            'analysis_json' => [
                'analysis'     => Fixtures::analysis(),
                'verification' => (new \App\Services\Verifier())->verify(Fixtures::analysis(), new DateTimeImmutable('2026-10-28')),
            ],
        ]);
    }

    public function testUploadFormRenders(): void
    {
        $result = $this->get('demo');

        $result->assertOK();
        $result->assertSee('Baca dokumen');
        $result->assertSee('csrf_test_name');
        $result->assertSee('putusan3.mahkamahagung.go.id/search.html');
        $result->assertSee('PA Wonosari');
    }

    public function testEarlierUploadsAreNotListed(): void
    {
        $this->storeAnalyzed('web');

        $this->get('demo')->assertDontSee('123/Pdt.G/2026/PA.Btl');
    }

    public function testSampleSummonsDownload(): void
    {
        $result = $this->get('demo/sample');

        $result->assertOK();
        $result->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $result->response()->getBody());
    }

    public function testResultShowsWhatsAppReplyAndChecks(): void
    {
        $id = $this->storeAnalyzed();

        $result = $this->get("demo/{$id}");

        $result->assertOK();
        $result->assertSee('Sedang saya baca… ⏳');
        $result->assertSee('<strong>Surat Panggilan Sidang (Relaas)</strong>');
        $result->assertSee('Pemeriksaan fakta (Verifier)');
        $result->assertSee('Pahami membantu Anda memahami dokumen, bukan nasihat hukum.');
    }

    public function testVerdictShowsSectionsTermsAndPages(): void
    {
        $analysis = Fixtures::analysis('putusan_sintetis');
        $id       = (new DocumentModel())->insert([
            'source' => 'web', 'mime' => 'application/pdf', 'status' => 'done',
            'analysis_json' => [
                'analysis'     => $analysis,
                'verification' => (new \App\Services\Verifier())->verify($analysis, new DateTimeImmutable('2026-10-28')),
                'input'        => ['jenis' => 'pdf', 'halaman' => [1, 2, 7, 67, 68], 'total_halaman' => 68],
            ],
        ]);

        $result = $this->get("demo/{$id}");

        $result->assertOK();
        $result->assertSee('halaman 1, 2, 7, 67, 68 dari 68');
        $result->assertSee('Bagian putusan');
        $result->assertSee('Dalam Rekonvensi: menolak gugatan Penggugat Rekonvensi');
        $result->assertSee('gugatan balik dari pihak yang digugat');
        $result->assertSee('(0274) 773059');
    }

    public function testFailedDocumentShowsUnreadableReply(): void
    {
        $id = (new DocumentModel())->insert([
            'source' => 'web', 'mime' => 'image/jpeg', 'status' => 'failed',
            'analysis_json' => ['error' => 'meta returned HTTP 503: overloaded', 'attempts' => 1],
        ]);

        $result = $this->get("demo/{$id}");

        $result->assertOK();
        $result->assertSee('Analisis gagal: meta returned HTTP 503: overloaded');
        $result->assertSee('Sebagian surat tidak terbaca.');
    }

    public function testWhatsAppDocumentsAreNotVisible(): void
    {
        $userId = (new UserModel())->insert(['phone' => '6281200000002']);
        $id     = $this->storeAnalyzed('whatsapp', $userId);

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get("demo/{$id}");
    }

    public function testUploadWithoutCsrfTokenIsRejected(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->post('demo', []);
    }
}
