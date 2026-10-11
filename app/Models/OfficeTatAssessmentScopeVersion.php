<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeTatAssessmentScopeVersion extends Model
{
    protected $fillable = ['unit', 'protected_area_id', 'workflow_keys', 'coverage_complete', 'version', 'configured_by'];

    protected function casts(): array
    {
        return ['workflow_keys' => 'array', 'coverage_complete' => 'boolean', 'version' => 'integer'];
    }
}
