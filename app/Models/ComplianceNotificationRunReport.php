<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceNotificationRunReport extends Model
{
    protected $fillable = ['source_type', 'source_id', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ComplianceNotificationRun::class, 'compliance_notification_run_id');
    }
}
