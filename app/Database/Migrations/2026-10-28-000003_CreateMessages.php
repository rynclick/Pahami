<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMessages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'           => ['type' => 'INT', 'unsigned' => true],
            'document_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'direction'         => ['type' => 'ENUM', 'constraint' => ['in', 'out']],
            'type'              => ['type' => 'ENUM', 'constraint' => ['text', 'image', 'pdf', 'audio']],
            'content'           => ['type' => 'TEXT', 'null' => true],
            'transcript'        => ['type' => 'TEXT', 'null' => true],
            'fonnte_message_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        // Conversation context = last 10 messages per user.
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('document_id', 'documents', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('messages', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('messages', true);
    }
}
