<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

final class OfficeTatNonReceiptRegisterVersion extends Model
{
    protected $fillable = [
        'participant_key', 'cutoff_date', 'obligation_keys', 'obligation_manifest', 'approving_authority', 'approval_reference',
        'rationale', 'evidence_id', 'version', 'configured_by',
    ];

    protected function casts(): array
    {
        return ['cutoff_date' => 'immutable_date', 'obligation_keys' => 'array', 'obligation_manifest' => 'array', 'version' => 'integer'];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(OfficeTatApprovalEvidence::class, 'evidence_id');
    }
}
