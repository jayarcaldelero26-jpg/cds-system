<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReportComplianceConfirmation extends Model
{
    public const EVENT_CONFIRMED = 'confirmed';
    public const EVENT_REVOKED = 'revoked';

    protected $fillable = [
        'source_type', 'source_id', 'event_type', 'confirmed_at', 'confirmed_by', 'remarks', 'snapshot',
        'original_confirmation_id', 'revoked_at', 'revoked_by', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'revoked_at' => 'datetime', 'snapshot' => 'array'];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function originalConfirmation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_confirmation_id');
    }
}
