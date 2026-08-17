<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aws extends Model
{
    use HasFactory;

    protected $table = 'aws';

    protected $fillable = [
        'protected_area_id',
        'station_name',
        'location',
        'report_period_type',
        'start_date',
        'end_date',
        'status',
        'recommendation_remarks',
        'report_file_name',
        'report_file_path',
        'timestamps', // Gibalik nato sa 'timestamps'
        'precipitation',
        'wind_direction',
        'wind_speed',
        'air_temperature',
        'relative_humidity',
        'atmospheric_pressure',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    public function protectedArea()
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
