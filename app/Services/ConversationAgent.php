<?php

namespace App\Services;

use App\Models\DocumentModel;
use App\Models\MessageModel;
use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use DateTimeImmutable;

/**
 * Follow-up Q&A on WhatsApp with the user's latest document as context
 * (CLAUDE.md §3.6, §7 flow 3, §8).
 *
 * Context = the analysis + verification of the latest document from the last
 * 7 days, plus the last 10 messages. Out-of-scope questions (outcome
 * prediction, strategy) are escalated to the PTSP desk and flagged.
 */
class ConversationAgent
{
    public const NO_DOCUMENT = 'Silakan kirim dulu foto atau PDF surat dari pengadilan yang ingin Anda tanyakan. Setelah saya baca, Anda bisa bertanya tentang isinya.';
    public const GANGGUAN    = 'Maaf, saya belum bisa menjawab sekarang karena ada gangguan. Silakan coba tanya lagi beberapa menit lagi.';

    private const PROMPT_FILE    = APPPATH . 'Prompts/conversation_agent.txt';
    private const HISTORY        = 10;
    private const DOCUMENT_DAYS  = 7;

    public function __construct(
        private readonly ModelClient $client,
        private readonly WhatsAppFormatter $formatter = new WhatsAppFormatter(),
        private readonly DocumentModel $documents = new DocumentModel(),
        private readonly MessageModel $messages = new MessageModel(),
    ) {
    }

    /**
     * @param int $questionMessageId The stored inbound message being answered (excluded from history)
     *
     * @return array{text: string, escalated: bool, document_id: ?int}
     */
    public function answer(int $userId, string $question, int $questionMessageId, DateTimeImmutable $now): array
    {
        $document = $this->documents
            ->where(['user_id' => $userId, 'status' => 'done'])
            ->where('created_at >=', $now->modify('-' . self::DOCUMENT_DAYS . ' days')->format('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->first();

        if ($document === null) {
            return ['text' => self::NO_DOCUMENT, 'escalated' => false, 'document_id' => null];
        }

        $messages = [
            ['role' => 'system', 'content' => (string) file_get_contents(self::PROMPT_FILE) . "\n\n" . $this->contextBlock($document, $now)],
            ...$this->history($userId, $questionMessageId),
            ['role' => 'user', 'content' => $question],
        ];

        try {
            $result = $this->client->chat($messages, 'conversation', [
                'reasoning_effort' => 'medium',
                'response_format'  => self::responseFormat(),
            ], (int) $document['id']);
        } catch (ModelException) {
            return ['text' => self::GANGGUAN, 'escalated' => false, 'document_id' => (int) $document['id']];
        }

        $reply = json_decode(trim(preg_replace('~^```(?:json)?|```$~m', '', $result->content)), true);
        if (! is_array($reply) || ! is_string($reply['jawaban'] ?? null) || trim($reply['jawaban']) === '') {
            log_message('error', 'ConversationAgent: unusable model reply for document ' . $document['id']);

            return ['text' => self::GANGGUAN, 'escalated' => false, 'document_id' => (int) $document['id']];
        }

        $escalated = ($reply['perlu_eskalasi'] ?? false) === true;
        $court     = $document['pengadilan_kode'];
        $text      = trim($reply['jawaban']);

        if ($escalated) {
            $text .= "\n\n" . $this->formatter->escalation($court);
        }
        $text .= "\n\n" . $this->formatter->disclaimer($court, ! $escalated);

        return ['text' => $text, 'escalated' => $escalated, 'document_id' => (int) $document['id']];
    }

    public static function responseFormat(): array
    {
        return ['type' => 'json_schema', 'json_schema' => [
            'name'   => 'jawaban_pahami',
            'schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'jawaban'         => ['type' => 'string'],
                    'perlu_eskalasi'  => ['type' => 'boolean'],
                    'alasan_eskalasi' => ['type' => ['string', 'null']],
                ],
                'required'             => ['jawaban', 'perlu_eskalasi', 'alasan_eskalasi'],
                'additionalProperties' => false,
            ],
        ]];
    }

    private function contextBlock(array $document, DateTimeImmutable $now): string
    {
        $json = $document['analysis_json'] ?? [];

        $data = [
            'tanggal_hari_ini' => $now->format('Y-m-d'),
            'analisis'         => $json['analysis'] ?? null,
            'verifikasi'       => $json['verification'] ?? null,
            'jadwal_terkonfirmasi_pengguna' => $json['konfirmasi'] ?? null,
            'jadwal_sidang_tercatat' => ['tanggal' => $document['sidang_tanggal'], 'jam' => $document['sidang_jam']],
        ];

        return "DATA DOKUMEN (hasil analisis, bukan teks asli):\n"
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Last messages as chat turns, oldest first. */
    private function history(int $userId, int $excludeId): array
    {
        $rows = $this->messages
            ->where('user_id', $userId)
            ->where('id <', $excludeId)
            ->orderBy('id', 'DESC')
            ->findAll(self::HISTORY);

        $turns = [];
        foreach (array_reverse($rows) as $row) {
            $text = $row['transcript'] ?? $row['content'];
            if ($text === null || trim($text) === '') {
                $text = $row['type'] === 'text' ? '' : "[mengirim {$row['type']}]";
            }
            if ($text === '') {
                continue;
            }
            $turns[] = ['role' => $row['direction'] === 'in' ? 'user' : 'assistant', 'content' => $text];
        }

        return $turns;
    }
}
