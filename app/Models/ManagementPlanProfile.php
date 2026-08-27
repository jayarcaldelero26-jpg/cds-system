<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagementPlanProfile extends Model
{
    use SoftDeletes;

    public const CHECKLIST_KEYS = [
        'executive_summary',
        'protected_area_description',
        'maps_spatial_information',
        'situational_analysis',
        'vision_goals_objectives',
        'management_strategies_activities',
        'human_resources_institutional_arrangement',
        'financial_plan',
        'monitoring_evaluation_indicators',
    ];

    protected $fillable = [
        'management_plan_type_id', 'protected_area_id', 'plan_name', 'planning_period_start', 'planning_period_end',
        'lead_office', 'lead_preparer', 'date_preparation_started', 'twg_constituted',
        'stakeholder_consultation_conducted', 'consultation_dates', 'completeness_checklist', 'approval_status',
        'pamb_resolution_number', 'pamb_resolution_date', 'cenro_endorsement_date', 'penro_endorsement_date',
        'red_endorsement_date', 'date_received_bmb', 'denr_affirmation_date', 'affirmation_reference',
        'harmonized_adsdpp', 'harmonized_clup', 'other_plans_integrated', 'documents', 'remarks',
        'created_by', 'updated_by',
    ];

    protected $appends = ['completeness_completed', 'completeness_total'];

    protected function casts(): array
    {
        return [
            'planning_period_start' => 'integer',
            'planning_period_end' => 'integer',
            'date_preparation_started' => 'date:Y-m-d',
            'twg_constituted' => 'boolean',
            'stakeholder_consultation_conducted' => 'boolean',
            'consultation_dates' => 'array',
            'completeness_checklist' => 'array',
            'pamb_resolution_date' => 'date:Y-m-d',
            'cenro_endorsement_date' => 'date:Y-m-d',
            'penro_endorsement_date' => 'date:Y-m-d',
            'red_endorsement_date' => 'date:Y-m-d',
            'date_received_bmb' => 'date:Y-m-d',
            'denr_affirmation_date' => 'date:Y-m-d',
            'documents' => 'array',
        ];
    }

    public function managementPlanType(): BelongsTo
    {
        return $this->belongsTo(ManagementPlanType::class);
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function getCompletenessCompletedAttribute(): int
    {
        return collect(self::CHECKLIST_KEYS)->filter(fn (string $key) => ($this->completeness_checklist[$key] ?? false) === true)->count();
    }

    public function getCompletenessTotalAttribute(): int
    {
        return count(self::CHECKLIST_KEYS);
    }
}
