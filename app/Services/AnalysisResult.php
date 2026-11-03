<?php

namespace App\Services;

/**
 * Outcome of DocumentAnalyzer::analyze().
 *
 * On success, analysis holds the schema-valid JSON with tingkat_keyakinan
 * replaced by the Verifier's value, and verification holds the field checks.
 * On failure, error says why and the caller replies "tidak terbaca".
 * input describes what was sent (image, or which PDF pages).
 */
final class AnalysisResult
{
    /** The model could not be reached or answered with an error. */
    public const FAILED_MODEL = 'model';
    /** The model answered, but not with schema-valid JSON (twice). */
    public const FAILED_SCHEMA = 'schema';
    /** The file itself could not be prepared (e.g. a broken PDF). */
    public const FAILED_INPUT = 'input';

    public function __construct(
        public readonly bool $ok,
        public readonly ?array $analysis,
        public readonly ?array $verification,
        public readonly ?string $error,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly int $attempts,
        public readonly ?array $input = null,
        public readonly ?string $failure = null,
    ) {
    }

    /**
     * Columns for the documents table.
     */
    public function documentFields(): array
    {
        if (! $this->ok) {
            return [
                'status'        => 'failed',
                'analysis_json' => ['error' => $this->error, 'attempts' => $this->attempts, 'input' => $this->input],
                'provider'      => $this->provider,
                'model'         => $this->model,
            ];
        }

        $a      = $this->analysis;
        $fields = $this->verification['fields'];
        // An uncertain date or time holds back the whole schedule until the user confirms (CLAUDE.md §8).
        $jadwalPasti = ! $this->verification['perlu_konfirmasi_jadwal'];

        return [
            'status'          => 'done',
            'analysis_json'   => ['analysis' => $a, 'verification' => $this->verification, 'input' => $this->input],
            'jenis_dokumen'   => $a['jenis_dokumen'],
            'jenis_perkara'   => $a['jenis_perkara'],
            'nomor_perkara'   => $a['nomor_perkara'],
            'pengadilan_kode' => $this->verification['pengadilan_kode'],
            // Only verified hearing data is promoted to columns the reminder planner reads.
            'sidang_tanggal' => $jadwalPasti && $fields['sidang_tanggal']['status'] === 'ok' ? $a['sidang']['tanggal'] : null,
            'sidang_jam'     => $jadwalPasti && $fields['sidang_jam']['status'] === 'ok' ? $a['sidang']['jam'] : null,
            'provider'       => $this->provider,
            'model'          => $this->model,
        ];
    }
}
