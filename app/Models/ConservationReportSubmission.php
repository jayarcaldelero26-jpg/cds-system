<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
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
        return $this->date_accomplished
            ? app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, $this->submissionRule()['working_days'], $this->target_office)->toDateString()
            : null;
    }

    public function getDaysCompliedAttribute(): int|string|null
    {
        if (! $this->date_accomplished) return null;
        if (! $this->date_received_penro) return 'Pending Submission by CENRO';
        return app(BusinessCalendarService::class)->workingDaysBetween($this->date_accomplished, $this->date_received_penro, 'after_through', $this->target_office);
    }

    public function getTimelinessAttribute(): string
    {
        $days = $this->days_complied;
        if ($days === null) return 'No Data';
        if (! is_int($days)) return $days;
        return $this->submissionRule()['timeliness_standard'] === 'A'
            ? match (true) { $days <= 11 => 'Outstanding', $days <= 13 => 'Very Satisfactory', $days <= 15 => 'Satisfactory', $days <= 29 => 'Unsatisfactory', $days <= 90 => 'Poor', default => 'No Rating' }
            : match (true) { $days <= 5 => 'Outstanding', $days === 6 => 'Very Satisfactory', $days === 7 => 'Satisfactory', $days <= 13 => 'Unsatisfactory', $days <= 62 => 'Poor', default => 'No Rating' };
    }

    public function getSubmissionStatusAttribute(): string
    {
        if (! $this->date_accomplished && ! $this->date_received_penro) return 'No Activity Conducted';
        if ($this->date_received_penro) return 'Report Submitted';
        return now(BusinessCalendarService::TIMEZONE)->startOfDay()->greaterThan($this->deadline_submission)
            ? 'Report Not Yet Submitted'
            : 'Ongoing Preparation at CENRO Level';
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
}
