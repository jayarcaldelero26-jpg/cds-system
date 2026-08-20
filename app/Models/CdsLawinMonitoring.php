<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdsLawinMonitoring extends Model
{
    protected $fillable = [
        'user_id',
        'patrol_area',
        'patrol_date',
        'team_leader',
        'team_members_count',
        'ecoregion',
        'threats_observed',
        'remarks',
        'status',
        'attachment',
    ];

    protected function casts(): array
    {
        return [
            'patrol_date' => 'date',
            'team_members_count' => 'integer',
        ];
    }
}
