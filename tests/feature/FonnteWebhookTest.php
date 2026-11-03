<?php

use App\Services\InboundDispatcher;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class FonnteWebhookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private InboundDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['FONNTE_WEBHOOK_SECRET'] = $_SERVER['FONNTE_WEBHOOK_SECRET'] = 'test-secret';

        $this->dispatcher = new class () extends InboundDispatcher {
            public array $dispatched = [];

            public function dispatch(int $messageId): void
            {
                $this->dispatched[] = $messageId;
            }
        };
        Services::injectMock('inboundDispatcher', $this->dispatcher);
    }

    private function webhook(array $payload, string $key = 'test-secret')
    {
        return $this->withBodyFormat('json')->post('webhook/fonnte?key=' . $key, $payload);
    }

    public function testGetCheckFromFonnteIsAnswered(): void
    {
        $result = $this->get('webhook/fonnte?key=test-secret');

        $result->assertOK();
        $result->assertJSONFragment(['detail' => 'ignored']);
        $this->seeNumRecords(0, 'messages', []);
    }

    public function testWrongSecretIsRejected(): void
    {
        $this->webhook(['sender' => '6281200000001', 'message' => 'halo'], 'salah')->assertStatus(403);
        $this->seeNumRecords(0, 'messages', []);
    }

    public function testMissingSecretConfigRefusesEverything(): void
    {
        $_ENV['FONNTE_WEBHOOK_SECRET'] = $_SERVER['FONNTE_WEBHOOK_SECRET'] = '';

        $this->webhook(['sender' => '6281200000001', 'message' => 'halo'], '')->assertStatus(503);
    }

    public function testTextMessageIsStoredAndDispatched(): void
    {
        $result = $this->webhook(['device' => '6281200000000', 'sender' => '6281200000001', 'name' => 'Budi Fiktif', 'message' => 'halo', 'inboxid' => 'abc1']);

        $result->assertOK();
        $result->assertJSONFragment(['detail' => 'queued']);
        $this->seeInDatabase('users', ['phone' => '6281200000001', 'name' => 'Budi Fiktif']);
        $this->seeInDatabase('messages', ['direction' => 'in', 'type' => 'text', 'content' => 'halo', 'fonnte_message_id' => 'abc1']);
        $this->assertCount(1, $this->dispatcher->dispatched);
    }

    public function testAttachmentTypeComesFromExtension(): void
    {
        $base = ['sender' => '6281200000001', 'message' => ''];

        $this->webhook($base + ['url' => 'https://fonnte.example/a.jpg', 'filename' => 'surat.jpg', 'extension' => 'jpg', 'inboxid' => '1']);
        $this->webhook($base + ['url' => 'https://fonnte.example/b.pdf', 'filename' => 'putusan.pdf', 'extension' => 'pdf', 'inboxid' => '2']);
        $this->webhook($base + ['url' => 'https://fonnte.example/c.ogg', 'filename' => 'voice.ogg', 'extension' => 'ogg', 'inboxid' => '3']);

        $this->seeInDatabase('messages', ['fonnte_message_id' => '1', 'type' => 'image', 'media_url' => 'https://fonnte.example/a.jpg', 'media_filename' => 'surat.jpg']);
        $this->seeInDatabase('messages', ['fonnte_message_id' => '2', 'type' => 'pdf']);
        $this->seeInDatabase('messages', ['fonnte_message_id' => '3', 'type' => 'audio']);
        $this->seeNumRecords(1, 'users', []);
    }

    public function testDuplicateDeliveryIsIgnored(): void
    {
        $payload = ['sender' => '6281200000001', 'message' => 'halo', 'inboxid' => 'same'];

        $this->webhook($payload);
        $this->webhook($payload)->assertJSONFragment(['detail' => 'duplicate']);

        $this->seeNumRecords(1, 'messages', []);
        $this->assertCount(1, $this->dispatcher->dispatched);
    }

    public function testGroupMessagesAreIgnored(): void
    {
        $this->webhook(['sender' => '120363000000000000@g.us', 'member' => '6281200000001', 'message' => 'halo'])
            ->assertJSONFragment(['detail' => 'ignored']);

        $this->seeNumRecords(0, 'messages', []);
        $this->assertSame([], $this->dispatcher->dispatched);
    }

    public function testReturningUserIsUpdatedNotDuplicated(): void
    {
        $this->webhook(['sender' => '6281200000001', 'message' => 'halo', 'inboxid' => 'a']);
        $this->webhook(['sender' => '6281200000001', 'message' => 'lagi', 'inboxid' => 'b']);

        $this->seeNumRecords(1, 'users', []);
        $this->seeNumRecords(2, 'messages', []);
    }
}
