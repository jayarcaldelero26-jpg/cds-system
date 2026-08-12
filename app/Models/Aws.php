<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aws extends Model
{
    use HasFactory;

    protected $table = 'aws';

    protected $fillable = [
        'station_name',
        'location',
        'latitude',
        'longitude',
        'status',
        'temperature',
        'humidity',
        'rainfall',
    ];
}
