<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Modules\ModuleDeadlineService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConservationReportSubmission extends Model
{
    use HasFactory;

    protected $fillable = ['workflow_key', 'protected_area_id', 'target_office', 'activity_name', 'document_type', 'reporting_period', 'date_conducted', 'date_accomplished', 'date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional', 'mov_file_name', 'mov_file_path', 'remarks', 'created_by', 'updated_by'];
    protected $appends = ['deadline_submission', 'days_complied', 'timeliness', 'submission_status', 'penro_delay'];

    protected function casts(): array
    {
        return ['date_accomplished' => 'date:Y-m-d', 'date_report_released_cenro' => 'date:Y-m-d', 'date_received_penro' => 'date:Y-m-d', 'date_endorsed_regional' => 'date:Y-m-d'];
    }

    public function protectedArea(): BelongsTo { return $this->belongsTo(ProtectedArea::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }

    public function getDeadlineSubmissionAttribute(): ?string
    {
        $pamb = app(PambComplianceCalculator::class);
        if ($pamb->applies($this->workflow_key)) {
            return $pamb->deadline($this);
        }

        if ($module = $this->moduleDefinition()) {
            return app(ModuleDeadlineService::class)->resolve($module, $this->date_accomplished, null, $this->date_received_penro, $this->target_office)['deadline_date'];
        }

        return $this->date_accomplished
            ? app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, $this->submissionRule()['working_days'], $this->target_office)->toDateString()
            : null;
    }

    public function getDaysCompliedAttribute(): int|string|null
    {
        $pamb = app(PambComplianceCalculator::class);
        if ($pamb->applies($this->workflow_key)) {
            return $pamb->daysComplied($this);
        }

        if (! $this->date_accomplished) return null;
        if (! $this->date_received_penro) return 'Pending Submission by CENRO';
        if ($module = $this->moduleDefinition()) {
            return app(ModuleDeadlineService::class)->resolve($module, $this->date_accomplished, null, $this->date_received_penro, $this->target_office)['processing_days'];
        }
        return app(BusinessCalendarService::class)->workingDaysBetween($this->date_accomplished, $this->date_received_penro, 'after_through', $this->target_office);
    }

    public function getTimelinessAttribute(): string
    {
        $pamb = app(PambComplianceCalculator::class);
        if ($pamb->applies($this->workflow_key)) {
            return $pamb->timeliness($this);
        }

        $days = $this->days_complied;
        if ($days === null) return 'No Data';
        if (! is_int($days)) return $days;

        $module = $this->moduleDefinition();
        if ($module && $module->deadline_mode !== ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS) {
            return 'No Data';
        }
        $standard = $module
            ? (($module->default_deadline_days ?? 0) <= 7 ? 'B' : 'A')
            : $this->submissionRule()['timeliness_standard'];

        return $standard === 'A'
            ? match (true) { $days <= 11 => 'Outstanding', $days <= 13 => 'Very Satisfactory', $days <= 15 => 'Satisfactory', $days <= 29 => 'Unsatisfactory', $days <= 90 => 'Poor', default => 'No Rating' }
            : match (true) { $days <= 5 => 'Outstanding', $days === 6 => 'Very Satisfactory', $days === 7 => 'Satisfactory', $days <= 13 => 'Unsatisfactory', $days <= 62 => 'Poor', default => 'No Rating' };
    }

    public function getSubmissionStatusAttribute(): string
    {
        return app(\App\Services\SubmissionTracking\RoutingStatusPresenter::class)->status($this);
    }

    public function getPenroDelayAttribute(): int|string
    {
        if (! $this->date_received_penro || ! $this->date_endorsed_regional) return 'Please Update Date Endorsed to Regional Office';
        return (int) $this->date_received_penro->diffInDays($this->date_endorsed_regional);
    }

    /** @return array{working_days: int, timeliness_standard: 'A'|'B'} */
    private function submissionRule(): array
    {
        return app(ConservationReportWorkflowRegistry::class)->submissionRule(
            $this->workflow_key ?? '',
            $this->activity_name,
            $this->document_type,
        );
    }

    private function moduleDefinition(): ?ModuleDefinition
    {
        if (app(ConservationReportWorkflowRegistry::class)->find((string) $this->workflow_key)) {
            return null;
        }

        return ModuleDefinition::query()->generic()->notRetired()->where('code', $this->workflow_key)->first();
    }
}
