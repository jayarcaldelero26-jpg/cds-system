<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeTatTieDecision extends Model
{
    protected $fillable = ['unit', 'period_key', 'metric_fingerprint', 'participant_keys', 'ordered_participant_keys', 'reason', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['participant_keys' => 'array', 'ordered_participant_keys' => 'array', 'approved_at' => 'immutable_datetime'];
    }
}
