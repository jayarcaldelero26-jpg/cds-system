<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagementPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'protected_area_id',
        'management_plan_type_id',
        'management_plan_profile_id',
        'target_office',
        'plan_type',
        'activity_name',
        'document_type',
        'semester',
        'date_conducted',
        'date_accomplished',
        'date_report_released_cenro',
        'date_received_penro',
        'date_endorsed_regional',
        'title',
        'version',
        'prepared_year',
        'approval_date',
        'valid_from',
        'valid_until',
        'status',
        'remarks',
        'attachments', // <--- Gi-update gikan sa attachment ngadto sa attachments
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

    protected $casts = [
        'prepared_year' => 'integer',
        'approval_date' => 'date',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'date_accomplished' => 'date:Y-m-d',
        'date_report_released_cenro' => 'date:Y-m-d',
        'date_received_penro' => 'date:Y-m-d',
        'date_endorsed_regional' => 'date:Y-m-d',
        'attachments' => 'array',
    ];

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function managementPlanType(): BelongsTo
    {
        return $this->belongsTo(ManagementPlanType::class);
    }

    public function managementPlanProfile(): BelongsTo
    {
        return $this->belongsTo(ManagementPlanProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getDeadlineSubmissionAttribute(): ?string
    {
        // Management Plans use the General/Standard-B seven-working-day standard.
        return $this->date_accomplished
            ? app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, 7, $this->target_office ?? null)->format('Y-m-d')
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

        return app(BusinessCalendarService::class)->workingDaysBetween($this->date_accomplished, $this->date_received_penro, 'after_through', $this->target_office ?? null);
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
        return app(\App\Services\SubmissionTracking\RoutingStatusPresenter::class)->status($this);
    }

    public function getTotalDaysDelayedPenroAttribute(): int|string
    {
        if (! $this->date_received_penro || ! $this->date_endorsed_regional) {
            return 'Please Update Date Endorsed to Regional Office';
        }

        return (int) $this->date_received_penro->diffInDays($this->date_endorsed_regional);
    }

    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end, ?string $office = null): int
    {
        return app(BusinessCalendarService::class)->workingDaysBetween($start, $end, 'after_through', $office);
    }
}
