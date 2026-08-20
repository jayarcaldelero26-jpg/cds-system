<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BamsFlora extends Model
{
    use HasFactory;

    protected $table = 'bams_flora';

    protected $fillable = [
        'protected_area_id',
        'plot_no',
        'quadrat_no',
        'transect_no',
        'date',
        'time',
        'observer',
        'vegetation_type',
        'weather',
        'elevation',
        'gps_unit',
        'lat',
        'long',
        'species_code',
        'dbh',
        'th',
        'mh',
        'bearing',
        'distance',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'quadrat_no' => 'integer',
            'transect_no' => 'integer',
            'elevation' => 'decimal:2',
            'lat' => 'decimal:7',
            'long' => 'decimal:7',
            'dbh' => 'decimal:2',
            'th' => 'decimal:2',
            'mh' => 'decimal:2',
            'distance' => 'decimal:2',
        ];
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
