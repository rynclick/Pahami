<?php

namespace App\Models;

use CodeIgniter\Model;

class ReminderModel extends Model
{
    protected $table         = 'reminders';
    protected $returnType    = 'array';
    protected $allowedFields = ['document_id', 'user_id', 'send_at', 'kind', 'status', 'sent_at'];
}
