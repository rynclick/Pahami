<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Replies that handed the user over to the PTSP desk (CLAUDE.md §8: "Log as escalated").
 */
class AddEscalatedToMessages extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('messages', [
            'escalated' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'media_filename'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('messages', 'escalated');
    }
}
