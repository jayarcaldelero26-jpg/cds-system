<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoricalReportOrigin extends Model
{
    protected $fillable = [
        'source',
        'source_record_id',
        'workflow_key',
        'encoded_by',
        'idempotency_key',
        'original_milestones',
        'encoded_at',
    ];

    protected function casts(): array
    {
        return [
            'original_milestones' => 'array',
            'encoded_at' => 'immutable_datetime',
        ];
    }
}
