<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BmsRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_area_id',
        'monitoring_date',
        'station',
        'time',
        'category',
        'taxonomic_group',
        'species_common_name',
        'species_scientific_name',
        'count',
        'observer_name',
        'latitude',
        'longitude',
        'elevation',
        'attachment',
        'remarks',
        // --- BAG-ONG MGA FIELDS PARA SA ANNEX FORMAT ---
        'location',
        'length_of_transect',
        'weather_condition',
        'ecosystem_type',
        'mode_of_observation',
    ];
    // Pwede nimo kuhaon ang semester base sa monitoring_date
    public function getSemesterAttribute()
    {
        $month = \Carbon\Carbon::parse($this->monitoring_date)->month;
        return $month <= 6 ? 'Sem 1' : 'Sem 2';
    }

    public function getYearAttribute()
    {
        return \Carbon\Carbon::parse($this->monitoring_date)->year;
    }

    public function protectedArea()
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
