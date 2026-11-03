<?php

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\ModelCallModel;
use App\Models\ReminderModel;
use App\Models\UserModel;
use App\Services\DemoDataReset;
use App\Services\MediaPurger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * demo:reset empties every data table and the upload folders.
 *
 * @internal
 */
final class DemoDataResetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pahami-reset-' . bin2hex(random_bytes(4)) . '/';
        foreach (MediaPurger::UPLOAD_DIRS as $dir) {
            mkdir($this->root . $dir, 0777, true);
            file_put_contents($this->root . $dir . 'index.html', '');
        }
        file_put_contents($this->root . 'uploads/whatsapp/a.jpg', 'bytes');
        file_put_contents($this->root . 'uploads/simulator/b.webm', 'bytes');

        $userId     = (new UserModel())->insert(['phone' => '6281200000001']);
        $documentId = (new DocumentModel())->insert(['user_id' => $userId, 'source' => 'whatsapp', 'status' => 'done', 'mime' => 'image/jpeg']);
        (new MessageModel())->insert(['user_id' => $userId, 'document_id' => $documentId, 'direction' => 'in', 'type' => 'text', 'content' => 'halo']);
        (new ReminderModel())->insert(['user_id' => $userId, 'document_id' => $documentId, 'send_at' => '2026-11-08 09:00:00', 'kind' => 'h3', 'status' => 'pending']);
        (new ModelCallModel())->insert(['provider' => 'meta', 'model' => 'muse-spark-1.3', 'purpose' => 'document_analysis', 'document_id' => $documentId]);
    }

    protected function tearDown(): void
    {
        foreach (MediaPurger::UPLOAD_DIRS as $dir) {
            array_map('unlink', glob($this->root . $dir . '*') ?: []);
            rmdir($this->root . $dir);
        }
        rmdir($this->root . 'uploads');
        rmdir($this->root);

        parent::tearDown();
    }

    public function testCountChangesNothing(): void
    {
        $reset = new DemoDataReset($this->db, $this->root);

        $this->assertSame(['model_calls' => 1, 'reminders' => 1, 'messages' => 1, 'documents' => 1, 'users' => 1, 'files' => 2], $reset->count());
        $this->seeNumRecords(1, 'users', []);
        $this->assertFileExists($this->root . 'uploads/whatsapp/a.jpg');
    }

    public function testResetEmptiesTablesAndUploadsButKeepsIndexFiles(): void
    {
        $deleted = (new DemoDataReset($this->db, $this->root))->reset();

        $this->assertSame(['model_calls' => 1, 'reminders' => 1, 'messages' => 1, 'documents' => 1, 'users' => 1, 'files' => 2], $deleted);
        foreach (DemoDataReset::TABLES as $table) {
            $this->seeNumRecords(0, $table, []);
        }
        $this->assertFileDoesNotExist($this->root . 'uploads/whatsapp/a.jpg');
        $this->assertFileDoesNotExist($this->root . 'uploads/simulator/b.webm');
        $this->assertFileExists($this->root . 'uploads/whatsapp/index.html');
    }
}
