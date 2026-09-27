<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentArchive extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'logical_slot', 'google_drive_file_id',
        'google_drive_folder_id', 'archived_sha256', 'archived_size',
        'archived_at', 'archived_by', 'archive_status', 'original_filename',
        'last_error',
        'remote_availability', 'last_verified_at', 'last_verification_error_class',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'last_verified_at' => 'datetime'];
    }
}
