<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protected_area_id', 'organizational_office_id', 'assignment_type', 'assigned_by'])]
class ProtectedAreaOfficeAssignment extends Model
{
    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OrganizationalOffice::class, 'organizational_office_id');
    }
}
