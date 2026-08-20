<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BmsThreat extends Model
{
    use HasFactory;

    protected $table = 'bms_threats';

    protected $fillable = [
        'protected_area_id',
        'date',
        'location',
        'threat_type',
        'threat_detail',
        'extent',
        'severity',
        'coord_format',
        'latitude',
        'longitude',
        'lat_deg',
        'lat_min',
        'lat_sec',
        'long_deg',
        'long_min',
        'long_sec',
        'utm_zone',
        'easting',
        'northing',
        'actions_taken',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    public function protectedArea()
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
