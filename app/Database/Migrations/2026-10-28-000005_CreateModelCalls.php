<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateModelCalls extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'provider'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'model'             => ['type' => 'VARCHAR', 'constraint' => 100],
            'purpose'           => ['type' => 'VARCHAR', 'constraint' => 50],
            'prompt_tokens'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'completion_tokens' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'latency_ms'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'document_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('document_id', 'documents', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('model_calls', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('model_calls', true);
    }
}
