<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BmsReportSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_area_id',
        'target_office',
        'activity_name',
        'document_type',
        'semester',
        'date_conducted',
        'date_accomplished',
        'date_report_released_cenro',
        'date_received_penro',
        'date_endorsed_regional',
        'mov_file_name',
        'mov_file_path',
        'remarks',
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
            'date_received_penro' => 'date:Y-m-d',
            'date_endorsed_regional' => 'date:Y-m-d',
        ];
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

    public function getDeadlineSubmissionAttribute(): ?string
    {
        // BMS, BAMS, and IMEA use the 15-working-day submission standard.
        // The 7-working-day standard applies only to General/Other Reports.
        return $this->date_accomplished
            ? $this->date_accomplished->copy()->addWeekdays(15)->format('Y-m-d')
            : null;
    }

    public function getNumberDaysCompliedAttribute(): int|string|null
    {
        if (! $this->date_accomplished) {
            return null;
        }

        if (! $this->date_received_penro) {
            return 'Pending Submission by CENRO';
        }

        return self::workingDaysAfterThrough($this->date_accomplished, $this->date_received_penro);
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
            $days <= 11 => 'Outstanding',
            $days <= 13 => 'Very Satisfactory',
            $days <= 15 => 'Satisfactory',
            $days <= 29 => 'Unsatisfactory',
            $days <= 90 => 'Poor',
            default => 'No Rating',
        };
    }

    public function getSubmissionStatusAttribute(): string
    {
        if (! $this->date_accomplished && ! $this->date_received_penro) {
            return 'No Activity Conducted';
        }

        if ($this->date_received_penro) {
            return 'Report Submitted';
        }

        return now()->startOfDay()->greaterThan($this->date_accomplished->copy()->addWeekdays(15)->startOfDay())
            ? 'Report Not Yet Submitted'
            : 'Ongoing Preparation at CENRO Level';
    }

    public function getTotalDaysDelayedPenroAttribute(): int|string
    {
        if (! $this->date_received_penro || ! $this->date_endorsed_regional) {
            return 'Please Update Date Endorsed to Regional Office';
        }

        return (int) $this->date_received_penro->diffInDays($this->date_endorsed_regional);
    }

    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start->copy()->addDay()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            if ($cursor->isWeekday()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }
}
