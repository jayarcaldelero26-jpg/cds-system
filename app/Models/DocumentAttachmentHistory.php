<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentAttachmentHistory extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'logical_slot', 'actor_id', 'action', 'remarks',
        'old_path', 'old_filename', 'old_sha256', 'old_size',
        'new_path', 'new_filename', 'new_sha256', 'new_size',
    ];
}
