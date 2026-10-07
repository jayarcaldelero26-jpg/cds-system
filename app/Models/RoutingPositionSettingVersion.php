<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class RoutingPositionSettingVersion extends Model
{
    protected $fillable = ['version', 'office_penro_enabled', 'penro_tsd_chief_enabled', 'saved_by', 'saved_at', 'reason'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Routing setting versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Routing setting versions are retained for route history.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'office_penro_enabled' => 'boolean', 'penro_tsd_chief_enabled' => 'boolean', 'saved_at' => 'immutable_datetime'];
    }

    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }
}
