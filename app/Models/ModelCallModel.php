<?php

namespace App\Models;

use CodeIgniter\Model;

class ModelCallModel extends Model
{
    protected $table         = 'model_calls';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'provider', 'model', 'purpose', 'prompt_tokens', 'completion_tokens', 'latency_ms', 'document_id', 'error',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
