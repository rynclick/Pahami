<?php

use App\Models\MessageModel;
use App\Models\UserModel;
use App\Services\ConversationAgent;
use App\Services\DocumentAnalyzer;
use App\Services\Fonnte\FonnteClient;
use App\Services\InboundHandler;
use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use App\Services\Model\ModelResult;
use App\Services\Model\Transcriber;
use App\Services\Model\Providers\ProviderInterface;
use App\Services\WhatsAppFormatter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class InboundHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    /** A 1x1 PNG, so finfo sees a real image. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private object $fonnte;

    private object $provider;

    /** What the fake Q&A model answers; null makes it fail. */
    private ?array $qaReply = ['jawaban' => 'Amarnya: gugatan dikabulkan.', 'perlu_eskalasi' => false, 'alasan_eskalasi' => null];

    /** What the fake transcriber hears; null makes it fail. */
    private ?string $heard = 'jadwal';

    private function fonnte(string $download): FonnteClient
    {
        return $this->fonnte = new class ($download) extends FonnteClient {
            public array $sent = [];

            public function __construct(private string $bytes)
            {
                parent::__construct('test-token');
            }

            public function sendText(string $target, string $message): ?string
            {
                $this->sent[] = [$target, $message];

                return (string) count($this->sent);
            }

            public function download(string $url): string
            {
                return $this->bytes;
            }
        };
    }

    /**
     * One fake model for both jobs: document analysis replies with $reply,
     * the Q&A request (recognized by its response schema) with $qaReply.
     *
     * @param string|ModelException $reply
     */
    private function provider($reply, ?array $qaReply): ProviderInterface
    {
        return $this->provider = new class ($reply, $qaReply) implements ProviderInterface {
            public array $qaRequests = [];

            public function __construct(private $reply, private ?array $qaReply)
            {
            }

            public function name(): string
            {
                return 'meta';
            }

            public function model(): string
            {
                return 'muse-spark-1.3';
            }

            public function supportsPdf(): bool
            {
                return true;
            }

            public function maxImages(): int
            {
                return 50;
            }

            public function chat(array $messages, array $params = []): ModelResult
            {
                if (($params['response_format']['json_schema']['name'] ?? '') === 'jawaban_pahami') {
                    $this->qaRequests[] = ['messages' => $messages, 'params' => $params];
                    if ($this->qaReply === null) {
                        throw new ModelException('meta returned HTTP 503', 503, 'meta');
                    }

                    return new ModelResult(json_encode($this->qaReply), 'meta', 'muse-spark-1.3', 3000, 200, 4000);
                }

                if ($this->reply instanceof ModelException) {
                    throw $this->reply;
                }

                return new ModelResult($this->reply, 'meta', 'muse-spark-1.3', 2500, 400, 5000);
            }
        };
    }

    private function transcriber(): Transcriber
    {
        return new class ($this->heard) extends Transcriber {
            public function __construct(private ?string $heard)
            {
                parent::__construct('test-key');
            }

            public function transcribe(string $audio, ?int $documentId = null): string
            {
                if ($this->heard === null) {
                    throw new ModelException('meta transcribe returned HTTP 400: unsupported WAV', 400, 'meta');
                }

                return $this->heard;
            }
        };
    }

    private function handler(string $download, $reply = ''): InboundHandler
    {
        $client = new ModelClient($this->provider($reply, $this->qaReply));

        return new class ($this->fonnte($download), new DocumentAnalyzer($client), new ConversationAgent($client), $this->transcriber()) extends InboundHandler {
            protected function pause(): void
            {
            }

            protected function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-28 10:00', new DateTimeZone('Asia/Jakarta'));
            }
        };
    }

    private function inbound(string $type, ?string $url = null, string $content = ''): int
    {
        $user   = (new UserModel())->where('phone', '6281200000001')->first();
        $userId = $user['id'] ?? (new UserModel())->insert(['phone' => '6281200000001']);

        return (new MessageModel())->insert([
            'user_id' => $userId, 'direction' => 'in', 'type' => $type, 'content' => $content,
            'media_url' => $url, 'media_filename' => $url !== null ? 'surat.png' : null,
        ]);
    }

    /** Sends a text message through a fresh handler and returns the reply. */
    private function text(string $content): string
    {
        $this->handler('')->handle($this->inbound('text', null, $content));

        return end($this->fonnte->sent)[1];
    }

    /** Processes the synthetic summons photo; $analysis overrides the fixture. */
    private function sendPhoto(array $analysis = []): void
    {
        $data = array_replace_recursive(Fixtures::analysis(), $analysis);
        $this->handler(base64_decode(self::PNG), json_encode($data))->handle($this->inbound('image', 'https://fonnte.example/a.png'));
    }

    /** @return list<string> */
    private function sentTexts(): array
    {
        return array_column($this->fonnte->sent, 1);
    }

    public function testTextGetsTheIntro(): void
    {
        $this->handler('')->handle($this->inbound('text'));

        $this->assertSame([['6281200000001', WhatsAppFormatter::INTRO]], $this->fonnte->sent);
        $this->seeInDatabase('messages', ['direction' => 'out', 'content' => WhatsAppFormatter::INTRO, 'fonnte_message_id' => '1']);
    }

    public function testVoiceNoteIsTranscribedEchoedAndHandledLikeText(): void
    {
        $messageId = $this->inbound('audio', 'https://fonnte.example/v.ogg');

        $this->handler('OggS fake voice')->handle($messageId);

        $sent = $this->sentTexts();
        $this->assertSame('🎙️ _Saya dengar:_ "jadwal"', $sent[0]);
        $this->assertStringStartsWith('Belum ada jadwal sidang', $sent[1]);
        $this->seeInDatabase('messages', ['id' => $messageId, 'transcript' => 'jadwal']);
    }

    public function testFailedTranscriptionAsksToType(): void
    {
        $this->heard = null;

        $this->handler('OggS')->handle($this->inbound('audio', 'https://fonnte.example/v.ogg'));

        $this->assertSame([WhatsAppFormatter::AUDIO_GAGAL], $this->sentTexts());
    }

    public function testSilentVoiceNoteAsksToRepeat(): void
    {
        $this->heard = '';

        $this->handler('OggS')->handle($this->inbound('audio', 'https://fonnte.example/v.ogg'));

        $this->assertSame([WhatsAppFormatter::AUDIO_KOSONG], $this->sentTexts());
    }

    public function testFollowUpQuestionIsAnsweredWithDocumentContext(): void
    {
        $this->sendPhoto();

        $reply = $this->text('amarnya apa aja');

        $this->assertStringStartsWith('Amarnya: gugatan dikabulkan.', $reply);
        $this->assertStringEndsWith('hubungi PTSP Pengadilan Agama Bantul (0274) 367423 atau PTSP Online: https://ptsp.pta-yogyakarta.go.id', $reply);

        $request = end($this->provider->qaRequests);
        $this->assertSame('medium', $request['params']['reasoning_effort']);
        $this->assertStringContainsString('"nomor_perkara":"123/Pdt.G/2026/PA.Btl"', $request['messages'][0]['content']);
        $this->assertSame(['role' => 'user', 'content' => 'amarnya apa aja'], end($request['messages']));
        // Earlier replies are in the history as assistant turns.
        $this->assertContains('assistant', array_column($request['messages'], 'role'));
        $this->cleanUploads();
    }

    public function testOutOfScopeQuestionIsEscalatedAndLogged(): void
    {
        $this->sendPhoto();
        $this->qaReply = ['jawaban' => 'Pertanyaan ini perlu dijawab petugas pengadilan.', 'perlu_eskalasi' => true, 'alasan_eskalasi' => 'outcome prediction'];

        $reply = $this->text('apakah saya akan menang?');

        $this->assertStringContainsString('📞 Silakan tanyakan ke PTSP Pengadilan Agama Bantul (0274) 367423', $reply);
        $this->assertStringContainsString('Ketik *ringkasan*', $reply);
        // The contact is given once, not again in the disclaimer.
        $this->assertSame(1, substr_count($reply, 'ptsp.pta-yogyakarta.go.id'));
        $this->seeInDatabase('messages', ['direction' => 'out', 'escalated' => 1]);

        $summary = $this->text('ringkasan');
        $this->assertStringStartsWith('📝 *Ringkasan untuk petugas PTSP*', $summary);
        $this->assertStringContainsString('Jenis perkara: Cerai Gugat', $summary);
        $this->assertStringContainsString('Pertanyaan saya: apakah saya akan menang?', $summary);
        $this->cleanUploads();
    }

    public function testQuestionWithoutRecentDocumentAsksForOne(): void
    {
        $this->assertSame(ConversationAgent::NO_DOCUMENT, $this->text('apa itu verstek?'));
    }

    public function testModelOutageDuringQuestionIsHonest(): void
    {
        $this->sendPhoto();
        $this->qaReply = null;

        $this->assertSame(ConversationAgent::GANGGUAN, $this->text('kapan sidangnya?'));
        $this->cleanUploads();
    }

    public function testPhotoIsReadAndExplained(): void
    {
        $messageId = $this->inbound('image', 'https://fonnte.example/a.png');

        $this->handler(base64_decode(self::PNG), json_encode(Fixtures::analysis()))->handle($messageId);

        $sent = $this->sentTexts();
        $this->assertSame(WhatsAppFormatter::READING, $sent[0]);
        $this->assertStringStartsWith('📄 *Surat Panggilan Sidang (Relaas)*', $sent[1]);
        $this->assertStringContainsString('📋 *Yang perlu dibawa:*', $sent[2]);
        $this->assertSame(WhatsAppFormatter::FOLLOW_UP, $sent[3]);

        $this->seeInDatabase('documents', ['source' => 'whatsapp', 'status' => 'done', 'mime' => 'image/png', 'nomor_perkara' => '123/Pdt.G/2026/PA.Btl', 'pengadilan_kode' => 'btl']);
        $doc = $this->db->table('documents')->get()->getRowArray();
        $this->assertFileExists(WRITEPATH . $doc['file_path']);
        unlink(WRITEPATH . $doc['file_path']);
        // The inbound message and every reply after "reading" point at the document.
        $this->seeInDatabase('messages', ['id' => $messageId, 'document_id' => $doc['id']]);
        $this->seeNumRecords(3, 'messages', ['direction' => 'out', 'document_id' => $doc['id']]);
    }

    public function testModelOutageIsNotBlamedOnThePhoto(): void
    {
        $this->handler(base64_decode(self::PNG), new ModelException('meta returned HTTP 503', 503, 'meta'))
            ->handle($this->inbound('image', 'https://fonnte.example/a.png'));

        $this->assertSame([WhatsAppFormatter::READING, WhatsAppFormatter::GANGGUAN], $this->sentTexts());
        $this->seeInDatabase('documents', ['status' => 'failed']);
        $this->cleanUploads();
    }

    public function testUnreadableReplyWhenModelOutputIsUnusable(): void
    {
        $this->handler(base64_decode(self::PNG), 'bukan json')->handle($this->inbound('image', 'https://fonnte.example/a.png'));

        $this->assertSame([WhatsAppFormatter::READING, WhatsAppFormatter::UNREADABLE], $this->sentTexts());
        $this->cleanUploads();
    }

    public function testFileThatIsNotAnImageOrPdfIsRefused(): void
    {
        $this->handler('PK just a zip file')->handle($this->inbound('image', 'https://fonnte.example/x.zip'));

        $this->assertSame([WhatsAppFormatter::READING, WhatsAppFormatter::FILE_TIDAK_DIDUKUNG], $this->sentTexts());
        $this->seeNumRecords(0, 'documents', []);
    }

    public function testPhotoWithFutureHearingSchedulesRemindersAndSaysSo(): void
    {
        $this->sendPhoto();

        $this->seeNumRecords(3, 'reminders', ['status' => 'pending']);
        $this->assertStringEndsWith('Saya akan mengingatkan Anda H-3, H-1, dan pagi hari sidang. Balas *stop* jika tidak perlu.', $this->sentTexts()[2]);
        $this->cleanUploads();
    }

    public function testCommandsAreCaseInsensitive(): void
    {
        $this->sendPhoto();

        $this->assertStringStartsWith('📋 *Yang perlu dibawa:*', $this->text('CHECKLIST'));
        $this->assertStringStartsWith('📅 *Jadwal sidang* perkara 123/Pdt.G/2026/PA.Btl', $this->text('Jadwal'));
        $this->assertStringStartsWith('🏛️ *Pengadilan Agama Bantul*', $this->text('pengadilan'));
        $this->assertStringStartsWith('🤝 *Yang bisa saya bantu:*', $this->text('Bantuan!'));
        $this->assertSame(WhatsAppFormatter::INTRO, $this->text('Halo'));
        $this->cleanUploads();
    }

    public function testStopCancelsReminders(): void
    {
        $this->sendPhoto();

        $this->assertStringStartsWith('✅ Pengingat sidang dihentikan.', $this->text('stop'));
        $this->seeNumRecords(0, 'reminders', ['status' => 'pending']);
        $this->assertSame('Tidak ada pengingat aktif.', $this->text('stop'));
        $this->cleanUploads();
    }

    public function testCommandsWithoutADocument(): void
    {
        $this->assertStringStartsWith('Belum ada surat', $this->text('checklist'));
        $this->assertStringStartsWith('Belum ada jadwal sidang', $this->text('jadwal'));
        $this->assertStringStartsWith('🏛️ *Pengadilan Agama di DIY*', $this->text('pengadilan'));
        $this->assertSame('Tidak ada jadwal yang perlu dikonfirmasi.', $this->text('ya'));
    }


    public function testUncertainTimeWaitsForYes(): void
    {
        // The quote says 10.00 but the model read 09.00: time uncertain, reminders held back.
        $this->sendPhoto(['kutipan' => ['sidang_jam' => 'Pukul : 10.00 WIB']]);
        $this->seeNumRecords(0, 'reminders', []);
        $this->assertStringContainsString('Balas *ya* atau kirim tanggal yang benar.', $this->sentTexts()[2]);

        $reply = $this->text('Ya');

        $this->assertStringStartsWith('✅ Terima kasih. Jadwal sidang dicatat: Selasa, 10 November 2026 jam 09.00 WIB.', $reply);
        $this->seeNumRecords(3, 'reminders', ['status' => 'pending']);
        $doc = (new \App\Models\DocumentModel())->first();
        $this->assertSame('pengguna', $doc['analysis_json']['konfirmasi']['oleh']);
        $this->assertSame('Tidak ada jadwal yang perlu dikonfirmasi.', $this->text('ya'));
        $this->cleanUploads();
    }

    public function testUserCanCorrectTheDate(): void
    {
        $this->sendPhoto(['kutipan' => ['sidang_tanggal' => 'Selasa / 11 November 2026']]);

        $reply = $this->text('sidangnya 17 November 2026 jam 13.30');

        $this->assertStringStartsWith('✅ Terima kasih. Jadwal sidang dicatat: Selasa, 17 November 2026 jam 13.30 WIB.', $reply);
        $this->seeInDatabase('documents', ['sidang_tanggal' => '2026-11-17', 'sidang_jam' => '13.30 WIB']);
        $this->seeInDatabase('reminders', ['kind' => 'h3', 'send_at' => '2026-11-14 09:00:00']);
        $this->cleanUploads();
    }

    private function cleanUploads(): void
    {
        foreach ($this->db->table('documents')->get()->getResultArray() as $doc) {
            if ($doc['file_path'] !== null && is_file(WRITEPATH . $doc['file_path'])) {
                unlink(WRITEPATH . $doc['file_path']);
            }
        }
    }
}
