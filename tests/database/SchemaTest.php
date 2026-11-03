<?php

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\ModelCallModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class SchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private function makeUser(): int
    {
        return (new UserModel())->insert(['phone' => '6281200000001']);
    }

    private function makeDocument(?int $userId, array $extra = []): int
    {
        return (new DocumentModel())->insert(array_merge([
            'user_id' => $userId,
            'source'  => $userId === null ? 'web' : 'whatsapp',
            'mime'    => 'image/jpeg',
        ], $extra));
    }

    public function testUserPhoneIsUnique(): void
    {
        $this->makeUser();

        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->makeUser();
    }

    public function testUserGetsCreatedAt(): void
    {
        $user = (new UserModel())->find($this->makeUser());

        $this->assertNotNull($user['created_at']);
    }

    public function testAnalysisJsonRoundTrips(): void
    {
        $analysis = [
            'jenis_dokumen' => 'relaas_panggilan',
            'sidang'        => ['tanggal' => '2026-11-03', 'jam' => '09.00', 'tempat' => null],
        ];
        $id = $this->makeDocument(null, ['analysis_json' => $analysis]);

        $doc = (new DocumentModel())->find($id);

        $this->assertSame($analysis, $doc['analysis_json']);
        $this->assertSame('received', $doc['status']);
    }

    public function testDocumentWithoutAnalysisReadsAsNull(): void
    {
        $doc = (new DocumentModel())->find($this->makeDocument(null));

        $this->assertNull($doc['analysis_json']);
    }

    public function testDeletingUserCascadesToTheirData(): void
    {
        $userId = $this->makeUser();
        $docId  = $this->makeDocument($userId);
        (new MessageModel())->insert(['user_id' => $userId, 'document_id' => $docId, 'direction' => 'in', 'type' => 'image']);
        (new ReminderModel())->insert(['user_id' => $userId, 'document_id' => $docId, 'send_at' => '2026-11-01 08:00:00', 'kind' => 'h3']);

        (new UserModel())->delete($userId);

        $this->seeNumRecords(0, 'documents', []);
        $this->seeNumRecords(0, 'messages', []);
        $this->seeNumRecords(0, 'reminders', []);
    }

    public function testDeletingDocumentKeepsModelCallLog(): void
    {
        $docId  = $this->makeDocument(null);
        $callId = (new ModelCallModel())->insert([
            'provider' => 'meta', 'model' => 'muse-spark-1.3', 'purpose' => 'document_analysis',
            'prompt_tokens' => 2500, 'completion_tokens' => 300, 'latency_ms' => 4200, 'document_id' => $docId,
        ]);

        (new DocumentModel())->delete($docId);

        $call = (new ModelCallModel())->find($callId);
        $this->assertNotNull($call);
        $this->assertNull($call['document_id']);
    }
}
