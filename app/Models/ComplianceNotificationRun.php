<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceNotificationRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const TYPE_AUTOMATIC = 'automatic';
    public const TYPE_MANUAL = 'manual';
    public const TYPE_TEST = 'test';
    public const TYPE_DRY_RUN = 'dry_run';
    public const ALERT_DUE_SOON = 'DUE_SOON';
    public const ALERT_DUE_TODAY = 'DUE_TODAY';
    public const ALERT_OVERDUE = 'OVERDUE';

    protected $fillable = [
        'run_date', 'recipient_key', 'idempotency_key', 'alert_type', 'recipients', 'cc_recipients', 'subject', 'report_count', 'status',
        'is_manual', 'run_type', 'sent_at', 'error_message', 'payload', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'run_date' => 'date', 'recipients' => 'array', 'cc_recipients' => 'array', 'is_manual' => 'boolean',
            'sent_at' => 'datetime', 'payload' => 'array',
        ];
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ComplianceNotificationRunReport::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
