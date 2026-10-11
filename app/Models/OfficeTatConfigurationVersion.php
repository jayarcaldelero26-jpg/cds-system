<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeTatConfigurationVersion extends Model
{
    protected $fillable = [
        'office_category',
        'office',
        'organizational_office_id',
        'period_key',
        'volume_target',
        'score_weights',
        'version',
        'configured_by',
    ];

    protected function casts(): array
    {
        return ['score_weights' => 'array'];
    }
}
