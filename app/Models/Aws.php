<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aws extends Model
{
    use HasFactory;

    protected $table = 'aws';

    protected $fillable = [
        'protected_area_id',
        'station_name',
        'location',
        'report_period_type',
        'activity_name',
        'document_type',
        'semester',
        'date_conducted',
        'date_accomplished',
        'date_report_released_cenro',
        'date_received_penro',
        'date_endorsed_regional',
        'start_date',
        'end_date',
        'status',
        'recommendation_remarks',
        'report_file_name',
        'report_file_path',
        'timestamps', // Gibalik nato sa 'timestamps'
        'precipitation',
        'wind_direction',
        'wind_speed',
        'air_temperature',
        'relative_humidity',
        'atmospheric_pressure',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'date_accomplished' => 'date:Y-m-d',
            'date_report_released_cenro' => 'date:Y-m-d',
            'date_received_penro' => 'date:Y-m-d',
            'date_endorsed_regional' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['deadline_submission', 'number_days_complied', 'timeliness', 'submission_status', 'total_days_delayed_penro'];

    public function protectedArea()
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function getDeadlineSubmissionAttribute(): ?string
    {
        return $this->date_accomplished?->copy()->addWeekdays(7)->format('Y-m-d');
    }

    public function getNumberDaysCompliedAttribute(): int|string|null
    {
        if (! $this->date_accomplished) return null;
        if (! $this->date_received_penro) return 'Pending Submission by CENRO';

        return self::workingDaysAfterThrough($this->date_accomplished, $this->date_received_penro);
    }

    public function getTimelinessAttribute(): string
    {
        $days = $this->number_days_complied;
        if ($days === null) return 'No Data';
        if (! is_int($days)) return $days;

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
        if (! $this->date_accomplished && ! $this->date_received_penro) return 'No Activity Conducted';
        if ($this->date_received_penro) return 'Report Submitted';

        return now()->startOfDay()->greaterThan($this->date_accomplished->copy()->addWeekdays(7)->startOfDay())
            ? 'Report Not Yet Submitted'
            : 'Ongoing Preparation at CENRO Level';
    }

    public function getTotalDaysDelayedPenroAttribute(): int|string
    {
        if (! $this->date_received_penro || ! $this->date_endorsed_regional) return 'Please Update Date Endorsed to Regional Office';

        return (int) $this->date_received_penro->diffInDays($this->date_endorsed_regional);
    }

    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->lessThanOrEqualTo($start)) return 0;
        $days = 0;
        $cursor = $start->copy()->addDay()->startOfDay();
        $lastDay = $end->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($lastDay)) {
            if ($cursor->isWeekday()) $days++;
            $cursor->addDay();
        }

        return $days;
    }
}
