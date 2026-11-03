<?php

use App\Services\Model\ModelException;
use App\Services\Model\Transcriber;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Transcriber with the HTTP call faked; the ffmpeg conversion is real.
 *
 * @internal
 */
final class TranscriberTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    /** @param list<array{status: int, body: string}> $responses */
    private function transcriber(array $responses): Transcriber
    {
        return new class ($responses) extends Transcriber {
            /** @var list<string> WAV bytes of each request */
            public array $posted = [];

            public function __construct(private array $responses)
            {
                parent::__construct('test-key');
            }

            protected function post(string $wav): array
            {
                $this->posted[] = $wav;

                return array_shift($this->responses);
            }
        };
    }

    /** One second of a 440 Hz tone as Ogg/Opus stereo 48 kHz, like a WhatsApp voice note. */
    private static function voiceNote(): string
    {
        $path = sys_get_temp_dir() . '/pahami-test-' . bin2hex(random_bytes(4)) . '.ogg';
        exec('ffmpeg -nostdin -loglevel error -f lavfi -i sine=frequency=440:duration=1 -ac 2 -ar 48000 -c:a libopus ' . escapeshellarg($path), $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('ffmpeg with libopus is not available');
        }
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function testVoiceNoteIsSentAsMono16kHzWavAndLogged(): void
    {
        $transcriber = $this->transcriber([['status' => 200, 'body' => '{"sessionId":"s1","transcript":"  kapan sidang saya?  ","audioDurationMs":1000}']]);

        $text = $transcriber->transcribe(self::voiceNote(), null);

        $this->assertSame('kapan sidang saya?', $text);
        $header = unpack('a4riff/Vsize/a4wave/a4fmt/Vlen/vformat/vchannels/Vrate', $transcriber->posted[0]);
        $this->assertSame(['RIFF', 'WAVE', 1, 1, 16000], [$header['riff'], $header['wave'], $header['format'], $header['channels'], $header['rate']]);
        $this->seeInDatabase('model_calls', ['purpose' => 'transcription', 'model' => Transcriber::MODEL, 'error' => null]);
    }

    public function testRetriesOnceOnServiceUnavailable(): void
    {
        $transcriber = $this->transcriber([
            ['status' => 503, 'body' => '{"error":{"message":"busy"}}'],
            ['status' => 200, 'body' => '{"transcript":"jadwal"}'],
        ]);

        $this->assertSame('jadwal', $transcriber->transcribe(self::voiceNote()));
        $this->assertCount(2, $transcriber->posted);
    }

    public function testRejectedAudioThrowsAndIsLogged(): void
    {
        $transcriber = $this->transcriber([['status' => 400, 'body' => '{"error":{"message":"unsupported WAV"}}']]);

        try {
            $transcriber->transcribe(self::voiceNote());
            $this->fail('expected ModelException');
        } catch (ModelException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertStringContainsString('unsupported WAV', $e->getMessage());
        }
        $this->assertCount(1, $transcriber->posted, '4xx is not retried');
        $this->seeInDatabase('model_calls', ['purpose' => 'transcription', 'error' => 'meta transcribe returned HTTP 400: unsupported WAV']);
    }

    public function testFileThatIsNotAudioFailsBeforeAnyRequest(): void
    {
        $transcriber = $this->transcriber([]);

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('Voice note could not be converted');

        try {
            $transcriber->transcribe('this is not audio');
        } finally {
            $this->assertSame([], $transcriber->posted);
        }
    }
}
