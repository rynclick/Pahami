<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Failed model calls are logged too; this column holds the error message.
 */
class AddErrorToModelCalls extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('model_calls', [
            'error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'document_id'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('model_calls', 'error');
    }
}
