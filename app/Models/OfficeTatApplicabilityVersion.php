<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OfficeTatApplicabilityVersion extends Model
{
    protected $fillable = [
        'protected_area_id', 'workflow_key', 'activity_name', 'document_type', 'status',
        'effective_from', 'effective_to', 'approving_authority', 'approval_reference',
        'rationale', 'version', 'evidence_id', 'configured_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'version' => 'integer'];
    }
}
