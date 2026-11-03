<?php

namespace App\Models;

use CodeIgniter\Model;

class DocumentModel extends Model
{
    protected $table         = 'documents';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'source', 'file_path', 'media_purged_at', 'mime', 'status', 'analysis_json',
        'jenis_dokumen', 'jenis_perkara', 'nomor_perkara', 'pengadilan_kode',
        'sidang_tanggal', 'sidang_jam', 'provider', 'model',
    ];

    // analysis_json is stored as a string and decoded to an array on read.
    protected array $casts = [
        'analysis_json' => '?json-array',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
