<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BamsFlora extends Model
{
    use HasFactory;

    protected $table = 'bams_flora';

    protected $guarded = ['id'];
}
