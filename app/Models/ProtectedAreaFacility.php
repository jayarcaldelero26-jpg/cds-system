<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProtectedAreaFacility extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_area_id',
        'inventory_date', // <-- GI-ADD NA NATO DINHI
        'facility_type',
        'unit_no',
        'year_established',
        'location_brgy_muni',
        'management_zone',
        'within_easement_zone',
        'coordinates',
        'source_of_fund',
        'description',
        'status',
        'typhoon_affected',
        'tenurial_instrument',
        'recommendations',
        'remarks',
    ];

    public function protectedArea()
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
