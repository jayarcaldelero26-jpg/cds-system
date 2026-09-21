<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionRoutingOverride extends Model
{
    protected $fillable = [
        'source', 'source_record_id', 'engine', 'action_key', 'event_key',
        'actual_actor_user_id', 'actual_actor_category', 'overridden_accountable_category',
        'overridden_office', 'protected_area_id', 'reason', 'authentication_method',
        'passkey_id', 'previous_stage', 'resulting_stage', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actual_actor_user_id');
    }
}