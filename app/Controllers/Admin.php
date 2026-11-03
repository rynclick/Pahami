<?php

namespace App\Controllers;

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Models\ModelCallModel;
use App\Models\ReminderModel;
use App\Services\Fonnte\SimulatorClient;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /admin for court staff (and the demo video): processed documents, questions
 * handed to the PTSP desk, reminders and model usage. Real WhatsApp users,
 * simulator sessions and web uploads are kept apart. Phone numbers are masked.
 * Behind the same basic auth as /demo (DEMO_PASSWORD).
 */
class Admin extends BaseController
{
    public const SOURCES = ['whatsapp', 'simulator', 'web', 'all'];

    private const LIST_LIMIT = 50;

    protected $helpers = ['url', 'whatsapp'];

    public function index(): string
    {
        $source = (string) $this->request->getGet('source');
        $source = in_array($source, self::SOURCES, true) ? $source : 'whatsapp';

        $counts = [];
        foreach (self::SOURCES as $s) {
            $counts[$s] = $this->documents($s)->countAllResults();
        }

        $documents = $this->documents($source)
            ->select('documents.*, users.phone')
            ->orderBy('documents.id', 'DESC')
            ->limit(self::LIST_LIMIT)
            ->get()->getResultArray();

        return view('admin/index', [
            'title'       => 'Admin · Pahami',
            'source'      => $source,
            'counts'      => $counts,
            'stats'       => $this->stats($source),
            'documents'   => $documents,
            'escalations' => $this->escalations($source),
            'usage'       => $this->usage($source),
        ]);
    }

    public function document(int $id): string
    {
        $doc = $this->find($id);

        $messages = [];
        if ($doc['user_id'] !== null) {
            // The conversation from this document on (follow-up questions carry no document id).
            $messages = (new MessageModel())->where('user_id', $doc['user_id'])
                ->where('created_at >=', $doc['created_at'])
                ->orderBy('id')->findAll(100);
        }

        return view('admin/document', [
            'title'     => 'Admin · #' . $id,
            'doc'       => $doc,
            'messages'  => $messages,
            'reminders' => (new ReminderModel())->where('document_id', $id)->orderBy('send_at')->findAll(),
            'calls'     => (new ModelCallModel())->where('document_id', $id)->orderBy('id')->findAll(),
        ]);
    }

    /** The stored upload, while it is still kept (media:purge removes it after 7 days). */
    public function file(int $id): ResponseInterface
    {
        $doc  = $this->find($id);
        $path = WRITEPATH . $doc['file_path'];

        if ($doc['file_path'] === null || $doc['media_purged_at'] !== null || ! is_file($path)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setContentType($doc['mime'])
            ->setHeader('Content-Disposition', 'inline')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents($path));
    }

    /** GET /admin/message/{id}/translate: English translation of one message (English mode). */
    public function translate(int $messageId): ResponseInterface
    {
        $message = (new MessageModel())->find($messageId);
        if ($message === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return DemoChat::translated((string) ($message['transcript'] ?? $message['content'] ?? ''), $this->response);
    }

    /** "whatsapp", "simulator" or "web" for one document row (with users.phone). */
    public static function sourceOf(array $row): string
    {
        if ($row['source'] === 'web') {
            return 'web';
        }

        return SimulatorClient::isSimulated((string) ($row['phone'] ?? '')) ? 'simulator' : 'whatsapp';
    }

    /** 6281234561304 → 62812•••1304; simulator users get no number. */
    public static function maskPhone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }
        if (SimulatorClient::isSimulated($phone)) {
            return 'sim-' . substr($phone, -4);
        }

        return substr($phone, 0, 5) . '•••' . substr($phone, -4);
    }

    private function find(int $id): array
    {
        $doc = (new DocumentModel())->find($id);
        if ($doc === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $doc['phone'] = $doc['user_id'] !== null ? (db_connect()->table('users')->select('phone')->where('id', $doc['user_id'])->get()->getRowArray()['phone'] ?? null) : null;

        return $doc;
    }

    /** documents LEFT JOIN users, narrowed to one source. */
    private function documents(string $source): BaseBuilder
    {
        $builder = db_connect()->table('documents')->join('users', 'users.id = documents.user_id', 'left');

        return match ($source) {
            'whatsapp'  => $builder->where('documents.source', 'whatsapp')->notLike('users.phone', SimulatorClient::PHONE_PREFIX, 'after'),
            'simulator' => $builder->where('documents.source', 'whatsapp')->like('users.phone', SimulatorClient::PHONE_PREFIX, 'after'),
            'web'       => $builder->where('documents.source', 'web'),
            default     => $builder,
        };
    }

    /** Narrows a builder that is joined with users to the users of one source (web uploads have none). */
    private function phoneFilter(BaseBuilder $builder, string $source): BaseBuilder
    {
        return match ($source) {
            'whatsapp'  => $builder->notLike('users.phone', SimulatorClient::PHONE_PREFIX, 'after'),
            'simulator' => $builder->like('users.phone', SimulatorClient::PHONE_PREFIX, 'after'),
            'web'       => $builder->where('users.id', 0),
            default     => $builder,
        };
    }

    private function stats(string $source): array
    {
        $db = db_connect();

        $done   = $this->documents($source)->where('documents.status', 'done')->countAllResults();
        $failed = $this->documents($source)->where('documents.status', 'failed')->countAllResults();
        $users  = $this->documents($source)->select('documents.user_id')->distinct()->where('documents.user_id IS NOT NULL')->countAllResults();

        $escalated = $this->phoneFilter($db->table('messages')->join('users', 'users.id = messages.user_id'), $source)
            ->where('messages.escalated', 1)->countAllResults();

        $reminders = [];
        foreach (['pending', 'sent', 'cancelled'] as $status) {
            $reminders[$status] = $this->phoneFilter($db->table('reminders')->join('users', 'users.id = reminders.user_id'), $source)
                ->where('reminders.status', $status)->countAllResults();
        }

        return ['done' => $done, 'failed' => $failed, 'users' => $users, 'escalated' => $escalated, 'reminders' => $reminders];
    }

    /** Escalated answers with the question that led to them. */
    private function escalations(string $source): array
    {
        $db   = db_connect();
        $rows = $this->phoneFilter($db->table('messages')->select('messages.*, users.phone')->join('users', 'users.id = messages.user_id'), $source)
            ->where('messages.escalated', 1)
            ->orderBy('messages.id', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        foreach ($rows as &$row) {
            $question = $db->table('messages')
                ->where(['user_id' => $row['user_id'], 'direction' => 'in', 'id <' => $row['id']])
                ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            $row['question'] = $question === null ? null : ($question['transcript'] ?? $question['content']);
        }

        return $rows;
    }

    /** Model calls of this source's documents, per provider and purpose. */
    private function usage(string $source): array
    {
        $builder = db_connect()->table('model_calls')
            ->select('model_calls.provider, model_calls.model, model_calls.purpose, COUNT(*) AS calls, AVG(model_calls.latency_ms) AS latency, '
                . 'SUM(model_calls.prompt_tokens) AS prompt_tokens, SUM(model_calls.completion_tokens) AS completion_tokens, '
                . 'SUM(model_calls.error IS NOT NULL) AS errors')
            ->groupBy(['model_calls.provider', 'model_calls.model', 'model_calls.purpose'])
            ->orderBy('calls', 'DESC');

        if ($source !== 'all') {
            $ids = array_column($this->documents($source)->select('documents.id')->get()->getResultArray(), 'id');
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('model_calls.document_id', $ids);
        }

        return $builder->get()->getResultArray();
    }
}
