<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FinalDocumentArchiveManifest extends Model
{
    public const PHASE = 'regional_return_final';

    protected $fillable = [
        'receipt_id', 'source_type', 'source_id', 'workflow_key', 'archive_phase', 'revision', 'intent_key',
        'archive_status', 'signature_confirmation', 'signature_set_sha256', 'signature_confirmed_by', 'signature_confirmed_at',
        'created_by', 'finalized_at', 'claim_token', 'claimed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'signature_confirmation' => 'boolean',
            'signature_confirmed_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FinalDocumentReceipt::class, 'receipt_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(FinalDocumentArchiveFile::class, 'manifest_id')->orderBy('ordinal');
    }
}
