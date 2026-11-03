<?php

namespace App\Models;

use CodeIgniter\Model;

class MessageModel extends Model
{
    protected $table         = 'messages';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'document_id', 'direction', 'type', 'content', 'transcript', 'media_url', 'media_filename', 'escalated', 'fonnte_message_id',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
