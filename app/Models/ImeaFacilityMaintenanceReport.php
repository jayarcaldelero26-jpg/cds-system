<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImeaFacilityMaintenanceReport extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $appends = ['deadline_submission', 'number_days_complied', 'timeliness', 'submission_status', 'total_days_delayed_penro'];
    protected function casts(): array { return ['date_accomplished' => 'date:Y-m-d', 'date_report_released_cenro' => 'date:Y-m-d', 'date_received_penro' => 'date:Y-m-d', 'date_endorsed_regional' => 'date:Y-m-d', 'mov_size' => 'integer']; }
    public function protectedArea(): BelongsTo { return $this->belongsTo(ProtectedArea::class); }
    public function getDeadlineSubmissionAttribute(): ?string { return $this->date_accomplished?->copy()->addWeekdays(7)->format('Y-m-d'); }
    public function getNumberDaysCompliedAttribute(): int|string|null { if (! $this->date_accomplished) return null; if (! $this->date_received_penro) return 'Pending Submission by CENRO'; return self::workingDaysAfterThrough($this->date_accomplished, $this->date_received_penro); }
    public function getTimelinessAttribute(): string { $days = $this->number_days_complied; if ($days === null) return 'No Data'; if (! is_int($days)) return $days; return match (true) { $days <= 5 => 'Outstanding', $days === 6 => 'Very Satisfactory', $days === 7 => 'Satisfactory', $days <= 13 => 'Unsatisfactory', $days <= 62 => 'Poor', default => 'No Rating' }; }
    public function getSubmissionStatusAttribute(): string { if (! $this->date_accomplished && ! $this->date_received_penro) return 'No Activity Conducted'; if ($this->date_received_penro) return 'Report Submitted'; return now()->startOfDay()->greaterThan($this->date_accomplished->copy()->addWeekdays(7)->startOfDay()) ? 'Report Not Yet Submitted' : 'Ongoing Preparation at CENRO Level'; }
    public function getTotalDaysDelayedPenroAttribute(): int|string { if (! $this->date_received_penro || ! $this->date_endorsed_regional) return 'Please Update Date Endorsed to Regional Office'; return (int) $this->date_received_penro->diffInDays($this->date_endorsed_regional); }
    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end): int { if ($end->lessThanOrEqualTo($start)) return 0; $days = 0; for ($cursor = $start->copy()->addDay()->startOfDay(), $last = $end->copy()->startOfDay(); $cursor->lessThanOrEqualTo($last); $cursor->addDay()) if ($cursor->isWeekday()) $days++; return $days; }
}
