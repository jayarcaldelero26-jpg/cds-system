<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class OfficeTatObligationScheduleVersion extends Model
{
    protected $fillable = [
        'protected_area_id', 'workflow_key', 'activity_name', 'document_type', 'reporting_year',
        'period_key', 'schedule_kind', 'occurrences', 'inventory_complete', 'approving_authority',
        'approval_reference', 'rationale', 'evidence_id', 'version', 'configured_by',
    ];

    protected function casts(): array
    {
        return ['occurrences' => 'array', 'inventory_complete' => 'boolean', 'reporting_year' => 'integer', 'version' => 'integer'];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(OfficeTatApprovalEvidence::class, 'evidence_id');
    }
}
