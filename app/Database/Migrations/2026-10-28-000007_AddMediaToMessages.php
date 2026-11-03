<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Inbound WhatsApp media: Fonnte sends a download URL, which the background
 * processor reads from here.
 */
class AddMediaToMessages extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('messages', [
            'media_url'      => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true, 'after' => 'transcript'],
            'media_filename' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'media_url'],
        ]);
        // Duplicate webhook deliveries are detected by this id.
        $this->db->query('CREATE INDEX messages_fonnte_message_id ON ' . $this->db->prefixTable('messages') . ' (fonnte_message_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX messages_fonnte_message_id ON ' . $this->db->prefixTable('messages'));
        $this->forge->dropColumn('messages', ['media_url', 'media_filename']);
    }
}
