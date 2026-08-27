<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_area_id',
        'target_office',
        'report_type',
        'semester',
        'activity_name',
        'date_conducted',
        'date_accomplished',
        'date_report_released_cenro',
        'submission_date',
        'date_endorsed_regional',
        'due_date',
        'status',
        'recommendations',
        'attachment',
        'attachment_original_name',
        'attachment_mime_type',
        'attachment_size',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'deadline_submission',
        'number_days_complied',
        'timeliness',
        'submission_status',
        'total_days_delayed_penro',
    ];

    protected function casts(): array
    {
        return [
            'date_accomplished' => 'date:Y-m-d',
            'date_report_released_cenro' => 'date:Y-m-d',
            'submission_date' => 'date:Y-m-d',
            'date_endorsed_regional' => 'date:Y-m-d',
            'attachment_size' => 'integer',
        ];
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function getDeadlineSubmissionAttribute(): ?string
    {
        // General/Other Reports use 7 working days. BMS/BAMS/IMEA use 15.
        return $this->date_accomplished
            ? app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, 7, $this->target_office ?? null)->format('Y-m-d')
            : null;
    }

    public function getNumberDaysCompliedAttribute(): int|string|null
    {
        if (! $this->date_accomplished) {
            return null;
        }

        if (! $this->submission_date) {
            return 'Pending Submission by CENRO';
        }

        return app(BusinessCalendarService::class)->workingDaysBetween($this->date_accomplished, $this->submission_date, 'after_through', $this->target_office ?? null);
    }

    public function getTimelinessAttribute(): string
    {
        $days = $this->number_days_complied;

        if ($days === null) {
            return 'No Data';
        }

        if (! is_int($days)) {
            return $days;
        }

        return match (true) {
            $days <= 5 => 'Outstanding',
            $days === 6 => 'Very Satisfactory',
            $days === 7 => 'Satisfactory',
            $days <= 13 => 'Unsatisfactory',
            $days <= 62 => 'Poor',
            default => 'No Rating',
        };
    }

    public function getSubmissionStatusAttribute(): string
    {
        if (! $this->date_accomplished && ! $this->submission_date && ! $this->deadline_submission) {
            return 'No Activity Conducted';
        }

        if ($this->submission_date) {
            return 'Report Submitted';
        }

        return now(BusinessCalendarService::TIMEZONE)->startOfDay()->greaterThan(
            app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, 7, $this->target_office ?? null)->startOfDay(),
        )
            ? 'Report Not Yet Submitted'
            : 'Ongoing Preparation at CENRO Level';
    }

    public function getTotalDaysDelayedPenroAttribute(): int|string
    {
        if (! $this->submission_date || ! $this->date_endorsed_regional) {
            return 'Please Update Date Endorsed to Regional Office';
        }

        return (int) $this->submission_date->diffInDays($this->date_endorsed_regional);
    }

    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end, ?string $office = null): int
    {
        return app(BusinessCalendarService::class)->workingDaysBetween($start, $end, 'after_through', $office);
    }
}
