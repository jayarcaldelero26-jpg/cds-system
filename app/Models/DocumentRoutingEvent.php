<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRoutingEvent extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'workflow_key', 'event_key', 'from_stage', 'to_stage',
        'from_office', 'to_office', 'occurred_at', 'recorded_by', 'remarks', 'metadata',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
