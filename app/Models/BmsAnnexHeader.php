<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BmsAnnexHeader extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_area_id', 'category', 'start_date', 'end_date',
        'location', 'date_conducted', 'start_end_time', 'start_gps', 'end_gps',
        'length_of_transect', 'weather_condition', 'elevation', 'ecosystem_type',
        'species_observed', 'observer',
    ];
}
