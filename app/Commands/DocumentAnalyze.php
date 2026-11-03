<?php

namespace App\Commands;

use App\Services\DocumentAnalyzer;
use App\Services\Model\ModelClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * Runs the document pipeline on a local file and prints the result.
 * Makes a real (billed) model call; nothing is stored in documents.
 */
class DocumentAnalyze extends BaseCommand
{
    protected $group       = 'Pahami';
    protected $name        = 'document:analyze';
    protected $description = 'Analyzes a JPG/PNG/PDF with the configured model and prints the JSON and verification.';
    protected $usage       = 'document:analyze <path> [--provider meta|baseten]';
    protected $arguments   = ['path' => 'File path inside the container'];
    protected $options     = ['--provider' => 'Override MODEL_PROVIDER for this run'];

    public function run(array $params)
    {
        $path = $params[0] ?? null;
        if ($path === null || ! is_file($path)) {
            CLI::error('File not found: ' . ($path ?? '(none)'));

            return EXIT_ERROR;
        }

        $provider = CLI::getOption('provider');
        $client   = is_string($provider) ? new ModelClient(ModelClient::makeProvider($provider)) : ModelClient::fromEnv();

        try {
            $result = (new DocumentAnalyzer($client))->analyze(
                (string) file_get_contents($path),
                (string) mime_content_type($path),
                basename($path),
            );
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        if (! $result->ok) {
            CLI::error("Analysis failed after {$result->attempts} attempt(s): {$result->error}");

            return EXIT_ERROR;
        }

        CLI::write("{$result->provider} / {$result->model} · attempts: {$result->attempts}", 'green');
        if (isset($result->input['halaman'])) {
            CLI::write("input: {$result->input['jenis']}, pages " . implode(',', $result->input['halaman']) . " of {$result->input['total_halaman']}", 'light_gray');
        }
        CLI::write(json_encode($result->analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        CLI::newLine();
        CLI::write('Verifier', 'yellow');
        foreach ($result->verification['fields'] as $field => $check) {
            CLI::write(sprintf('  %-16s %-12s %s', $field, $check['status'], $check['alasan'] ?? ''));
        }
        CLI::write('  pengadilan_kode: ' . ($result->verification['pengadilan_kode'] ?? 'null'));
        CLI::write('  sidang_sudah_lewat: ' . var_export($result->verification['sidang_sudah_lewat'], true));
        CLI::write('  perlu_konfirmasi_jadwal: ' . var_export($result->verification['perlu_konfirmasi_jadwal'], true));
        CLI::write('  tingkat_keyakinan: ' . $result->verification['tingkat_keyakinan']);
        CLI::write('  teks_sumber_dicek: ' . var_export($result->verification['teks_sumber_dicek'], true));
        CLI::write('  kutipan_amar_di_teks: ' . var_export($result->verification['kutipan_amar_di_teks'], true));

        return EXIT_SUCCESS;
    }
}
