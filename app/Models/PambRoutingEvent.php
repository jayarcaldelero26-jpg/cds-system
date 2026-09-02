<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PambRoutingEvent extends Model
{
    protected $fillable = [
        'conservation_report_submission_id',
        'workflow_key',
        'stage_key',
        'occurred_at',
        'recorded_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ConservationReportSubmission::class, 'conservation_report_submission_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
