<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportTrackingSequence extends Model
{
    protected $fillable = ['domain', 'reporting_year', 'next_value'];

    protected function casts(): array
    {
        return ['reporting_year' => 'integer', 'next_value' => 'integer'];
    }
}
