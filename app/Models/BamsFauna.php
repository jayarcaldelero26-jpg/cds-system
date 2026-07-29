<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BamsFauna extends Model
{
    use HasFactory;

    protected $table = 'bams_fauna';

    protected $guarded = ['id'];
}
