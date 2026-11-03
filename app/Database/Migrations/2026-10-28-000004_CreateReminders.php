<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReminders extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'document_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'send_at'     => ['type' => 'DATETIME'],
            'kind'        => ['type' => 'ENUM', 'constraint' => ['h3', 'h1', 'h0', 'custom']],
            'status'      => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'sent', 'cancelled'],
                'default'    => 'pending',
            ],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        // The cron command scans pending reminders that are due.
        $this->forge->addKey(['status', 'send_at']);
        $this->forge->addForeignKey('document_id', 'documents', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('reminders', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('reminders', true);
    }
}
