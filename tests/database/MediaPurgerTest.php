<?php

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\UserModel;
use App\Services\MediaPurger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * media:purge (CLAUDE.md §9) on a temporary upload folder.
 *
 * @internal
 */
final class MediaPurgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private string $root;

    private int $userId;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pahami-purge-' . bin2hex(random_bytes(4)) . '/';
        foreach (MediaPurger::UPLOAD_DIRS as $dir) {
            mkdir($this->root . $dir, 0777, true);
        }
        $this->userId = (new UserModel())->insert(['phone' => '6281200000009']);
        $this->now    = new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('Asia/Jakarta'));
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

    /** A stored upload whose row and file are $daysOld days old. */
    private function upload(string $name, int $daysOld): int
    {
        $created = $this->now->modify("-{$daysOld} days");
        file_put_contents($this->root . 'uploads/whatsapp/' . $name, 'bytes');
        touch($this->root . 'uploads/whatsapp/' . $name, $created->getTimestamp());

        $id = (new DocumentModel())->insert([
            'user_id' => $this->userId, 'source' => 'whatsapp', 'file_path' => 'uploads/whatsapp/' . $name,
            'mime'    => 'image/png', 'status' => 'done',
        ]);
        $this->db->table('documents')->where('id', $id)->update(['created_at' => $created->format('Y-m-d H:i:s')]);

        return $id;
    }

    private function mediaMessage(int $daysOld): int
    {
        $id = (new MessageModel())->insert([
            'user_id' => $this->userId, 'direction' => 'in', 'type' => 'audio',
            'media_url' => 'https://fonnte.example/voice.ogg', 'transcript' => 'kapan sidang saya?',
        ]);
        $this->db->table('messages')->where('id', $id)
            ->update(['created_at' => $this->now->modify("-{$daysOld} days")->format('Y-m-d H:i:s')]);

        return $id;
    }

    private function purger(): MediaPurger
    {
        return new MediaPurger(new DocumentModel(), new MessageModel(), $this->root);
    }

    public function testOldFilesAreDeletedAndTheAnalysisKept(): void
    {
        $old    = $this->upload('old.png', 8);
        $recent = $this->upload('recent.png', 2);

        $stats = $this->purger()->purge($this->now, 7);

        $this->assertSame(['documents' => 1, 'files' => 0, 'links' => 0], $stats);
        $this->assertFileDoesNotExist($this->root . 'uploads/whatsapp/old.png');
        $this->assertFileExists($this->root . 'uploads/whatsapp/recent.png');
        $this->seeInDatabase('documents', ['id' => $old, 'file_path' => 'uploads/whatsapp/old.png', 'media_purged_at' => '2026-11-10 10:00:00', 'status' => 'done']);
        $this->seeInDatabase('documents', ['id' => $recent, 'media_purged_at' => null]);

        // A second run has nothing left to do.
        $this->assertSame(['documents' => 0, 'files' => 0, 'links' => 0], $this->purger()->purge($this->now, 7));
    }

    public function testOrphanFilesAndOldMediaLinksGoToo(): void
    {
        file_put_contents($this->root . 'uploads/demo/orphan.pdf', 'bytes');
        touch($this->root . 'uploads/demo/orphan.pdf', $this->now->modify('-9 days')->getTimestamp());
        file_put_contents($this->root . 'uploads/demo/index.html', '');
        touch($this->root . 'uploads/demo/index.html', $this->now->modify('-30 days')->getTimestamp());
        $oldMessage = $this->mediaMessage(8);
        $newMessage = $this->mediaMessage(1);

        $stats = $this->purger()->purge($this->now, 7);

        $this->assertSame(['documents' => 0, 'files' => 1, 'links' => 1], $stats);
        $this->assertFileDoesNotExist($this->root . 'uploads/demo/orphan.pdf');
        $this->assertFileExists($this->root . 'uploads/demo/index.html');
        // The transcript stays: it is the user's question, not the recording.
        $this->seeInDatabase('messages', ['id' => $oldMessage, 'media_url' => null, 'transcript' => 'kapan sidang saya?']);
        $this->seeInDatabase('messages', ['id' => $newMessage, 'media_url' => 'https://fonnte.example/voice.ogg']);
    }

    public function testDryRunOnlyCounts(): void
    {
        $id = $this->upload('old.png', 8);
        $this->mediaMessage(8);

        $stats = $this->purger()->purge($this->now, 7, true);

        $this->assertSame(['documents' => 1, 'files' => 0, 'links' => 1], $stats);
        $this->assertFileExists($this->root . 'uploads/whatsapp/old.png');
        $this->seeInDatabase('documents', ['id' => $id, 'media_purged_at' => null]);
    }
}
