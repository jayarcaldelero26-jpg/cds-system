<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImeaAssessment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'protected_area_id',
        'pamo_name',
        'assessment_year',
        'assessment_period',
        'visitor_arrivals',
        'trail_condition',
        'solid_waste_generation_kg',
        'wildlife_disturbance',
        'vegetation_damage',
        'water_quality',
        'carrying_capacity_compliance',
        'community_benefits_income',
        'visitor_satisfaction_rate',
        'biodiversity_impact_notes',
        'environment_impact_notes',
        'social_cultural_impact_notes',
        'economic_impact_notes',
        'general_remarks',
        'status',          // Gidugang apil ang status kon naa sa imong table
        'attachments',     // <--- IMPORTANTE: Gidugang aron ma-save sa database
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'attachments' => 'array', // <--- IMPORTANTE: Aronawtomatikong ma-convert isip array ang JSON sa database
        'carrying_capacity_compliance' => 'boolean',
    ];

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
