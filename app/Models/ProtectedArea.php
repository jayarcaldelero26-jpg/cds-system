<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'short_name', 'category', 'municipality', 'province', 'region',
    'area_hectares', 'pamo', 'pasu', 'year_established', 'legal_basis',
    'description', 'status', 'remarks', 'created_by', 'updated_by',
])]
class ProtectedArea extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['area_hectares' => 'decimal:2', 'year_established' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function managementPlans(): HasMany
    {
        return $this->hasMany(ManagementPlan::class);
    }
    public function technicalReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TechnicalReport::class);
    }
}
