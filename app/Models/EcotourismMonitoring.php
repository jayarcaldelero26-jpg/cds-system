<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcotourismMonitoring extends Model
{
    use HasFactory;

    protected $table = 'ecotourism_monitorings';

    protected $fillable = [
        'protected_area_id',
        'site_name',
        'monitoring_date',
        'visitors_count',
        'impact_rating',
        'issues_observed',
        'recommendations',
        'status',
        'attachment',
    ];

    protected $casts = [
        'monitoring_date' => 'date',
    ];

    // Relasyon padulong sa Protected Area
    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
