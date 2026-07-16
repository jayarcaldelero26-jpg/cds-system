<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LawinMonitoring extends Model
{
    use HasFactory;

    protected $table = 'lawin_monitorings';

    protected $fillable = [
        'protected_area_id',
        'patrol_date',
        'patrol_distance',
        'patrol_hours',
        'patrol_members_count',
        'threats_observed',
        'remarks',
        'status',
        'attachment',
    ];

    protected $casts = [
        'patrol_date' => 'date',
        'patrol_distance' => 'float',
        'patrol_hours' => 'float',
        'patrol_members_count' => 'integer',
    ];

    // Relasyon padulong sa Protected Area
    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
