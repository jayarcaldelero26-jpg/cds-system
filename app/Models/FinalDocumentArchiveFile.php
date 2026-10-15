<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FinalDocumentArchiveFile extends Model
{
    protected $fillable = [
        'manifest_id', 'logical_identity', 'ordinal', 'original_filename', 'mime_type', 'file_size', 'sha256',
        'staged_path', 'file_status', 'document_archive_id', 'attempt_count', 'claim_token', 'claimed_at',
        'verified_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer', 'file_size' => 'integer', 'attempt_count' => 'integer',
            'claimed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
        ];
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(FinalDocumentArchiveManifest::class, 'manifest_id');
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(DocumentArchive::class, 'document_archive_id');
    }
}
