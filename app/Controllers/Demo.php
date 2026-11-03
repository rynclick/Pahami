<?php

namespace App\Controllers;

use App\Models\DocumentModel;
use App\Models\ModelCallModel;
use App\Services\DocumentAnalyzer;
use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use App\Services\ReminderPlanner;
use App\Services\SyntheticSummons;
use DateTimeImmutable;
use DateTimeZone;
use App\Services\Verifier;
use App\Services\WhatsAppFormatter;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Web demo: upload a document, see the JSON, the checks and the WhatsApp reply.
 * Only documents with source=web are visible here.
 */
class Demo extends BaseController
{
    private const UPLOAD_DIR = WRITEPATH . 'uploads/demo/';

    /** Court names as listed in the Supreme Court's decision directory filter. */
    public const DIRECTORY_COURTS = ['PTA Yogyakarta', 'PA Yogyakarta', 'PA Sleman', 'PA Bantul', 'PA Wates', 'PA Wonosari'];

    protected $helpers = ['form', 'url', 'whatsapp'];

    public function index(): string
    {
        // Earlier uploads are listed on /admin (web uploads), not here.
        return view('demo/index', [
            'defaultProvider' => (string) env('MODEL_PROVIDER', 'meta'),
            'courts'          => self::DIRECTORY_COURTS,
        ]);
    }

    /** GET /demo/sample: a fresh synthetic summons (fictional parties, hearing 10 days ahead) to download and upload. */
    public function sample(): ResponseInterface
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));

        return $this->response
            ->setContentType('image/png', '')
            ->setHeader('Content-Disposition', 'attachment; filename="relaas_contoh_sintetis.png"')
            ->setBody(SyntheticSummons::png(SyntheticSummons::hearingFrom($today), $today));
    }

    public function upload(): ResponseInterface
    {
        $rules = [
            'dokumen' => [
                'label' => 'Dokumen',
                'rules' => 'uploaded[dokumen]|max_size[dokumen,' . (DocumentAnalyzer::MAX_BYTES / 1000) . ']'
                    . '|ext_in[dokumen,jpg,jpeg,png,pdf]|mime_in[dokumen,' . implode(',', DocumentAnalyzer::SUPPORTED_MIME) . ']',
                'errors' => [
                    'uploaded' => 'Pilih file foto (JPG/PNG) atau PDF terlebih dahulu.',
                    'max_size' => 'Ukuran file maksimal 20 MB.',
                    'ext_in'   => 'Hanya file JPG, PNG, atau PDF.',
                    'mime_in'  => 'Hanya file JPG, PNG, atau PDF.',
                ],
            ],
            'provider' => ['label' => 'Model', 'rules' => 'permit_empty|in_list[meta,baseten]'],
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('demo')->withInput()->with('error', implode(' ', $this->validator->getErrors()));
        }

        $file     = $this->request->getFile('dokumen');
        $mime     = $file->getMimeType();
        $provider = (string) $this->request->getPost('provider');

        $original = $file->getClientName();
        $stored   = $file->getRandomName();
        $file->move(self::UPLOAD_DIR, $stored);

        $documents = new DocumentModel();
        $id        = $documents->insert([
            'source'    => 'web',
            'file_path' => 'uploads/demo/' . $stored,
            'mime'      => $mime,
            'status'    => 'processing',
        ]);

        // PDFs and retries can take longer than PHP's default 30 s.
        set_time_limit(300);

        try {
            $client = $provider !== '' ? new ModelClient(ModelClient::makeProvider($provider)) : ModelClient::fromEnv();
            $result = (new DocumentAnalyzer($client))->analyze(
                (string) file_get_contents(self::UPLOAD_DIR . $stored),
                $mime,
                $original,
                $id,
            );
            $documents->update($id, $result->documentFields());
        } catch (ModelException|InvalidArgumentException $e) {
            // Setup problems (missing API key, unsupported file) before any model reply.
            $documents->update($id, ['status' => 'failed', 'analysis_json' => ['error' => $e->getMessage(), 'attempts' => 0]]);
        }

        return redirect()->to('demo/' . $id);
    }

    public function show(int $id): string
    {
        $doc = $this->webDocument($id);
        $json = $doc['analysis_json'] ?? [];

        $analysis     = $json['analysis'] ?? null;
        $verification = $json['verification'] ?? null;

        return view('demo/result', [
            'doc'          => $doc,
            'analysis'     => $analysis,
            'verification' => $verification,
            'error'        => $json['error'] ?? null,
            'input'        => $json['input'] ?? null,
            'replies'      => self::replies($doc),
            'calls'        => (new ModelCallModel())->where('document_id', $id)->orderBy('id')->findAll(),
            'keyFields'    => Verifier::KEY_FIELDS,
        ]);
    }

    /** GET /demo/{id}/translate/{n}: English translation of reply n (English mode). */
    public function translate(int $id, int $n): ResponseInterface
    {
        $replies = self::replies($this->webDocument($id));
        if (! isset($replies[$n])) {
            throw PageNotFoundException::forPageNotFound();
        }

        return DemoChat::translated($replies[$n], $this->response);
    }

    /** The WhatsApp messages this document produces, starting with "Sedang saya baca…". */
    private static function replies(array $doc): array
    {
        $json         = $doc['analysis_json'] ?? [];
        $analysis     = $json['analysis'] ?? null;
        $verification = $json['verification'] ?? null;
        if ($analysis === null) {
            return [WhatsAppFormatter::READING, WhatsAppFormatter::UNREADABLE];
        }

        // Web uploads have no WhatsApp user; show the reminders that would be scheduled.
        $kinds = array_column(ReminderPlanner::plan($doc['sidang_tanggal'], new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'))), 'kind');

        return [WhatsAppFormatter::READING, ...(new WhatsAppFormatter())->documentReply($analysis, $verification, $kinds)];
    }

    /** Streams the uploaded file back for the side-by-side view. */
    public function file(int $id): ResponseInterface
    {
        $doc  = $this->webDocument($id);
        $path = WRITEPATH . $doc['file_path'];

        if ($doc['file_path'] === null || ! is_file($path)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setContentType($doc['mime'])
            ->setHeader('Content-Disposition', 'inline')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents($path));
    }

    private function webDocument(int $id): array
    {
        $doc = (new DocumentModel())->where('source', 'web')->find($id);

        if ($doc === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $doc;
    }
}
