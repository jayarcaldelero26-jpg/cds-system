<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OfficeTatApprovalEvidence extends Model
{
    protected $fillable = ['public_id', 'scope_key', 'storage_path', 'original_name', 'media_type', 'byte_size', 'sha256', 'uploaded_by'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }
}
