<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RoutingPositionSetting extends Model
{
    protected $table = 'routing_position_settings';
    protected $fillable = ['id', 'setting_version_id'];

    public function settingVersion(): BelongsTo
    {
        return $this->belongsTo(RoutingPositionSettingVersion::class, 'setting_version_id');
    }
}
