<?php

namespace App\Services;

use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Image/PDF → Muse Spark → schema-valid JSON → Verifier.
 *
 * If the reply is not valid JSON or breaks the schema, retries once with the
 * errors appended (CLAUDE.md §6). After that it gives up and returns a
 * failed AnalysisResult; the caller replies with the "tidak terbaca" message.
 */
class DocumentAnalyzer
{
    public const SUPPORTED_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

    /** Meta's inline limit is 50 MB; WhatsApp media is far smaller. */
    public const MAX_BYTES = 20_000_000;

    private const PROMPT_FILE = APPPATH . 'Prompts/document_analyzer.txt';

    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(
        private readonly ModelClient $client,
        private readonly Verifier $verifier = new Verifier(),
        private readonly PdfPageSelector $pdf = new PdfPageSelector(),
    ) {
    }

    public function analyze(
        string $bytes,
        string $mime,
        string $filename = 'dokumen',
        ?int $documentId = null,
        ?DateTimeImmutable $today = null,
    ): AnalysisResult {
        if (! in_array($mime, self::SUPPORTED_MIME, true)) {
            throw new InvalidArgumentException("Unsupported file type {$mime}; expected JPG, PNG or PDF.");
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('File is larger than ' . (self::MAX_BYTES / 1_000_000) . ' MB.');
        }

        $today ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));

        try {
            [$parts, $input, $note, $sourceText] = $mime === 'application/pdf'
                ? $this->pdfParts($bytes, $filename)
                : [[ModelClient::imagePart($bytes, $mime)], ['jenis' => 'gambar'], '', null];
        } catch (RuntimeException $e) {
            return new AnalysisResult(false, null, null, $e->getMessage(), null, null, 0, null, AnalysisResult::FAILED_INPUT);
        }

        $messages = [
            ['role' => 'system', 'content' => (string) file_get_contents(self::PROMPT_FILE)],
            ['role' => 'user', 'content' => [
                ModelClient::textPart($this->todayLine($today) . $note . "\nBaca dokumen terlampir dan kembalikan JSON sesuai schema."),
                ...$parts,
            ]],
        ];
        $params = ['reasoning_effort' => 'low', 'response_format' => DocumentSchema::responseFormat()];

        $provider = $model = null;
        $errors   = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $result = $this->client->chat($messages, 'document_analysis', $params, $documentId);
            } catch (ModelException $e) {
                return new AnalysisResult(false, null, null, $e->getMessage(), $e->provider, null, $attempt, $input, AnalysisResult::FAILED_MODEL);
            }

            [$provider, $model] = [$result->provider, $result->model];
            $data   = json_decode(self::stripFences($result->content), true);
            $errors = is_array($data) ? DocumentSchema::validate($data) : ['reply is not valid JSON: ' . json_last_error_msg()];

            if ($errors === []) {
                $verification = $this->verifier->verify($data, $today, $sourceText);
                // The derived confidence replaces the model's self-report.
                $data['tingkat_keyakinan'] = $verification['tingkat_keyakinan'];

                return new AnalysisResult(true, $data, $verification, null, $provider, $model, $attempt, $input);
            }

            // Retry once: show the model its own reply and what was wrong with it.
            $messages[] = ['role' => 'assistant', 'content' => $result->content];
            $messages[] = ['role' => 'user', 'content' => "JSON di atas tidak sesuai schema:\n- "
                . implode("\n- ", array_slice($errors, 0, 20))
                . "\nKembalikan hanya JSON yang sudah diperbaiki."];
        }

        return new AnalysisResult(false, null, null, 'Schema validation failed: ' . implode('; ', array_slice($errors, 0, 5)), $provider, $model, 2, $input, AnalysisResult::FAILED_SCHEMA);
    }

    /**
     * Long PDFs are cut to the pages that matter; providers without PDF
     * input get those pages as PNG images (CLAUDE.md §8 fallback).
     *
     * @return array{0: list<array>, 1: array, 2: string, 3: string} [content parts, input metadata, note for the model, text layer]
     */
    private function pdfParts(string $bytes, string $filename): array
    {
        $provider = $this->client->mediaProvider();
        $asPdf    = $provider->supportsPdf();
        $excerpt  = $this->pdf->prepare($bytes, $asPdf ? null : $provider->maxImages());

        $parts = $asPdf
            ? [ModelClient::pdfPart($excerpt['bytes'], $filename)]
            : array_map(static fn ($png) => ModelClient::imagePart($png, 'image/png'), $this->pdf->toImages($excerpt['bytes']));

        $input = ['jenis' => $asPdf ? 'pdf' : 'pdf_sebagai_gambar', 'halaman' => $excerpt['pages'], 'total_halaman' => $excerpt['total']];

        $note = '';
        if (count($excerpt['pages']) < $excerpt['total']) {
            $note = "\nDokumen ini PDF {$excerpt['total']} halaman. Yang dilampirkan hanya halaman "
                . implode(', ', $excerpt['pages'])
                . ' (bagian awal, duduk perkara, petitum, rekonvensi, pertimbangan hukum, dan amar sampai akhir).';
        }

        return [$parts, $input, $note, $excerpt['text']];
    }

    private function todayLine(DateTimeImmutable $today): string
    {
        $readable = (int) $today->format('j') . ' ' . self::BULAN[(int) $today->format('n')] . ' ' . $today->format('Y');

        return "Tanggal hari ini (Asia/Jakarta): {$today->format('Y-m-d')} ({$readable}).";
    }

    /** Models sometimes wrap JSON in ```json fences despite instructions. */
    private static function stripFences(string $content): string
    {
        $content = trim($content);

        if (preg_match('~^```(?:json)?\s*(.*?)\s*```$~s', $content, $m)) {
            return $m[1];
        }

        return $content;
    }
}
