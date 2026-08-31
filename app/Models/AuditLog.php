<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = [
        'event_type', 'action', 'entity_type', 'entity_id', 'module', 'summary', 'metadata',
        'user_id', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
