<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDocuments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            // Nullable: uploads from the /demo page have no WhatsApp user.
            'user_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'source'    => ['type' => 'ENUM', 'constraint' => ['whatsapp', 'web']],
            'file_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'mime'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'status'    => [
                'type'       => 'ENUM',
                'constraint' => ['received', 'processing', 'done', 'failed'],
                'default'    => 'received',
            ],
            'analysis_json'   => ['type' => 'JSON', 'null' => true],
            'jenis_dokumen'   => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'jenis_perkara'   => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'nomor_perkara'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'pengadilan_kode' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'sidang_tanggal'  => ['type' => 'DATE', 'null' => true],
            'sidang_jam'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'provider'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'model'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        // Latest document per user is the conversation context.
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('documents', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('documents', true);
    }
}
