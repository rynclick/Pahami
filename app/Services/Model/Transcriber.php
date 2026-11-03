<?php

namespace App\Services\Model;

use App\Models\ModelCallModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;
use CURLFile;
use CURLStringFile;

/**
 * Voice note → text with Muse Voice Transcribe (Meta Model API).
 *
 * Per https://dev.meta.ai/docs/api-reference/voice/transcribe (read Oct 29):
 * POST /v1/asr/transcribe, multipart with a JSON "request" part and an
 * "audio" part that must be WAV (mono PCM, 16 or 24 kHz), at most 10 minutes
 * and 32 MB. WhatsApp voice notes are Ogg/Opus, so they go through ffmpeg first.
 * Every call is logged to model_calls like chat calls.
 */
class Transcriber
{
    public const MODEL = 'muse-voice-transcribe-1.0';

    private const MAX_SECONDS = 600;

    /** Words the model should expect in court-related voice notes. */
    private const KEYWORDS = ['Pengadilan Agama', 'PTSP', 'relaas', 'sidang', 'Penggugat', 'Tergugat', 'Pemohon', 'Termohon', 'verstek', 'verzet', 'rekonvensi', 'amar', 'putusan', 'akta cerai', 'nafkah', 'iddah', "mut'ah", 'hadhanah'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.meta.ai/v1',
        private readonly int $timeoutSeconds = 60,
        private readonly ModelCallModel $calls = new ModelCallModel(),
    ) {
        if ($apiKey === '') {
            throw new ModelException('META_API_KEY is not set in .env (needed for voice notes).', null, 'meta');
        }
    }

    public static function fromEnv(): self
    {
        return new self(
            (string) env('META_API_KEY', ''),
            (string) env('META_BASE_URL', 'https://api.meta.ai/v1'),
            (int) env('MODEL_TIMEOUT', 120),
        );
    }

    /**
     * @param string $audio Voice note bytes in any format ffmpeg reads (Ogg/Opus from WhatsApp)
     *
     * @return string Transcript; empty when nothing was said
     *
     * @throws ModelException
     */
    public function transcribe(string $audio, ?int $documentId = null): string
    {
        $started = hrtime(true);

        try {
            $wav      = $this->toWav($audio);
            $response = $this->post($wav);
            if (in_array($response['status'], [429, 500, 503], true)) {
                usleep(1_000_000);
                $response = $this->post($wav);
            }

            if ($response['status'] !== 200) {
                $data = json_decode($response['body'], true);

                throw new ModelException('meta transcribe returned HTTP ' . $response['status'] . ': ' . ($data['error']['message'] ?? mb_substr($response['body'], 0, 200)), $response['status'], 'meta');
            }

            $data = json_decode($response['body'], true);
            if (! is_array($data) || ! isset($data['transcript'])) {
                throw new ModelException('meta transcribe returned no transcript.', 200, 'meta');
            }
        } catch (ModelException $e) {
            $this->log($started, $documentId, mb_substr($e->getMessage(), 0, 255));

            throw $e;
        }

        $this->log($started, $documentId, null);

        return trim((string) $data['transcript']);
    }

    /** Converts to mono 16 kHz 16-bit PCM WAV, as the endpoint requires. */
    protected function toWav(string $audio): string
    {
        $dir = sys_get_temp_dir() . '/pahami-audio-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        try {
            file_put_contents("{$dir}/in", $audio);
            exec(sprintf(
                'ffmpeg -nostdin -loglevel error -y -i %s -t %d -ac 1 -ar 16000 -c:a pcm_s16le -map_metadata -1 %s 2>&1',
                escapeshellarg("{$dir}/in"),
                self::MAX_SECONDS,
                escapeshellarg("{$dir}/out.wav"),
            ), $output, $code);

            if ($code !== 0 || ! is_file("{$dir}/out.wav")) {
                throw new ModelException('Voice note could not be converted: ' . implode(' ', array_slice($output, 0, 2)), null, 'meta');
            }

            return (string) file_get_contents("{$dir}/out.wav");
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    /**
     * @return array{status: int, body: string}
     */
    protected function post(string $wav): array
    {
        $request = json_encode([
            'model'         => self::MODEL,
            'audioEncoding' => 'WAV',
            'mode'          => 'PUSH_TO_TALK',
            'languageBias'  => ['Indonesian'],
            'keywords'      => self::KEYWORDS,
        ], JSON_UNESCAPED_UNICODE);

        $tmp = tempnam(sys_get_temp_dir(), 'pahami-wav');
        file_put_contents($tmp, $wav);

        try {
            $response = Services::curlrequest([], null, null, false)->post(rtrim($this->baseUrl, '/') . '/asr/transcribe', [
                'headers'     => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'multipart'   => [
                    'request' => new CURLStringFile($request, 'request.json', 'application/json'),
                    'audio'   => new CURLFile($tmp, 'audio/wav', 'voice.wav'),
                ],
                'timeout'     => $this->timeoutSeconds,
                'http_errors' => false,
            ]);
        } catch (HTTPException $e) {
            throw new ModelException("meta transcribe request failed: {$e->getMessage()}", null, 'meta');
        } finally {
            @unlink($tmp);
        }

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    private function log(int $started, ?int $documentId, ?string $error): void
    {
        $this->calls->insert([
            'provider'    => 'meta',
            'model'       => self::MODEL,
            'purpose'     => 'transcription',
            'latency_ms'  => (int) ((hrtime(true) - $started) / 1_000_000),
            'document_id' => $documentId,
            'error'       => $error,
        ]);
    }
}
