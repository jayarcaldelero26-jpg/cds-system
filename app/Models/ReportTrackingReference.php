<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportTrackingReference extends Model
{
    protected $fillable = ['tracking_number', 'domain', 'source_type', 'source_id', 'reporting_year'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'reporting_year' => 'integer'];
    }
}
