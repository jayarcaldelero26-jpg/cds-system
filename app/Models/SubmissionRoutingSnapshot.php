<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SubmissionRoutingSnapshot extends Model
{
    protected $fillable = ['source_key', 'source_id', 'setting_version_id', 'profile', 'graph_version', 'captured_by', 'captured_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Submission route snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Submission route snapshots are retained for route history.'));
    }

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'captured_at' => 'immutable_datetime'];
    }
}
