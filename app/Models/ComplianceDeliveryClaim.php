<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceDeliveryClaim extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'idempotency_key', 'run_type', 'business_date', 'recipient_key', 'delivery_fingerprint',
        'status', 'attempts', 'acquired_at', 'completed_at', 'last_run_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'acquired_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(ComplianceNotificationRun::class, 'last_run_id');
    }
}
