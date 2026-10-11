<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeTatTechnicalClassificationVersion extends Model
{
    protected $fillable = ['domain', 'workflow_key', 'is_highly_technical', 'version', 'configured_by', 'approving_authority', 'approval_reference', 'rationale', 'effective_from', 'effective_to', 'evidence_id'];

    protected function casts(): array
    {
        return ['is_highly_technical' => 'boolean', 'version' => 'integer', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
