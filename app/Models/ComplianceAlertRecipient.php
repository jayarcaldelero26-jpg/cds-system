<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceAlertRecipient extends Model
{
    protected $fillable = [
        'protected_area_id', 'target_office', 'target_office_key', 'recipient_name', 'attention_line', 'recipient_email', 'cc_emails', 'is_active',
        'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['cc_emails' => 'array', 'is_active' => 'boolean'];
    }

    public function logicalScopeKey(): ?string
    {
        if ($this->protected_area_id) {
            return 'pa:'.(int) $this->protected_area_id;
        }

        $office = trim((string) $this->target_office_key);

        return $office === '' ? null : 'office:'.$office;
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
