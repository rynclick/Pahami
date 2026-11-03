<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Empties all conversation data and uploaded files, e.g. before recording a
 * demo. Cached translations are kept (they only save model calls).
 */
class DemoDataReset
{
    /** Children first, so foreign keys never block a delete. */
    public const TABLES = ['model_calls', 'reminders', 'messages', 'documents', 'users'];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly string $root = WRITEPATH,
    ) {
    }

    /** Current row and file counts, without changing anything. */
    public function count(): array
    {
        $db     = $this->db ?? Database::connect();
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = $db->table($table)->countAllResults();
        }

        return $counts + ['files' => count($this->files())];
    }

    /** @return array<string, int> what was deleted, per table plus 'files' */
    public function reset(): array
    {
        $db      = $this->db ?? Database::connect();
        $deleted = [];
        foreach (self::TABLES as $table) {
            $deleted[$table] = $db->table($table)->countAllResults();
            $db->table($table)->emptyTable();
        }

        $files = $this->files();
        foreach ($files as $path) {
            unlink($path);
        }

        return $deleted + ['files' => count($files)];
    }

    /** @return list<string> uploaded files (the folders' index.html stays) */
    private function files(): array
    {
        $files = [];
        foreach (MediaPurger::UPLOAD_DIRS as $dir) {
            foreach (glob($this->root . $dir . '*') ?: [] as $path) {
                if (is_file($path) && basename($path) !== 'index.html') {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }
}
