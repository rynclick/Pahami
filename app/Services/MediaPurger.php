<?php

namespace App\Services;

use App\Models\DocumentModel;
use App\Models\MessageModel;
use DateTimeImmutable;

/**
 * Deletes uploaded documents and voice-note links older than the retention
 * period (CLAUDE.md §9). The analysis, reminders and messages stay, so the
 * user still gets reminders and answers; only the file itself is gone.
 */
class MediaPurger
{
    /** Folders under writable/ that hold uploads. */
    public const UPLOAD_DIRS = ['uploads/whatsapp/', 'uploads/demo/', 'uploads/simulator/'];

    public function __construct(
        private readonly DocumentModel $documents = new DocumentModel(),
        private readonly MessageModel $messages = new MessageModel(),
        private readonly string $root = WRITEPATH,
    ) {
    }

    /**
     * @return array{documents: int, files: int, links: int}
     */
    public function purge(DateTimeImmutable $now, int $days, bool $dryRun = false): array
    {
        $cutoff = $now->modify("-{$days} days");
        $stats  = ['documents' => 0, 'files' => 0, 'links' => 0];

        $old = $this->documents
            ->where('created_at <', $cutoff->format('Y-m-d H:i:s'))
            ->where('file_path IS NOT NULL')
            ->where('media_purged_at IS NULL')
            ->findAll();

        $purged = [];
        foreach ($old as $document) {
            $stats['documents']++;
            $purged[$this->root . $document['file_path']] = true;
            if ($dryRun) {
                continue;
            }

            $this->delete($this->root . $document['file_path']);
            $this->documents->update($document['id'], ['media_purged_at' => $now->format('Y-m-d H:i:s')]);
        }

        // Files without a documents row: an upload whose analysis crashed, a test leftover.
        foreach (self::UPLOAD_DIRS as $dir) {
            foreach (glob($this->root . $dir . '*') ?: [] as $path) {
                if (is_file($path) && ! isset($purged[$path]) && basename($path) !== 'index.html'
                    && filemtime($path) < $cutoff->getTimestamp()) {
                    $stats['files']++;
                    if (! $dryRun) {
                        $this->delete($path);
                    }
                }
            }
        }

        // Fonnte media links (photos, PDFs, voice notes) stop being stored too.
        $links = $this->messages->builder()
            ->where('created_at <', $cutoff->format('Y-m-d H:i:s'))
            ->where('media_url IS NOT NULL');
        if ($dryRun) {
            $stats['links'] = $links->countAllResults();
        } else {
            $links->update(['media_url' => null]);
            $stats['links'] = $links->db()->affectedRows();
        }

        return $stats;
    }

    private function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
