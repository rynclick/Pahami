<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When media:purge deleted the uploaded file (CLAUDE.md §9). file_path is kept.
 */
class AddMediaPurgedAtToDocuments extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('documents', [
            'media_purged_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'file_path'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('documents', 'media_purged_at');
    }
}
