<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpafManagementReport extends Model
{
    protected $guarded = [];
    protected $appends = ['deadline_submission', 'number_days_complied', 'timeliness', 'submission_status', 'total_days_delayed_penro'];
    protected function casts(): array { return ['date_accomplished'=>'date:Y-m-d','date_report_released_cenro'=>'date:Y-m-d','date_received_penro'=>'date:Y-m-d','date_endorsed_regional'=>'date:Y-m-d','mov_size'=>'integer']; }
    public function protectedArea(): BelongsTo { return $this->belongsTo(ProtectedArea::class); }
    public function getDeadlineSubmissionAttribute(): ?string { return $this->date_accomplished ? app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, 7, $this->target_office ?? null)->format('Y-m-d') : null; }
    public function getNumberDaysCompliedAttribute(): int|string|null { if (!$this->date_accomplished) return null; if (!$this->date_received_penro) return 'Pending Submission by CENRO'; return app(BusinessCalendarService::class)->workingDaysBetween($this->date_accomplished, $this->date_received_penro, 'after_through', $this->target_office ?? null); }
    public function getTimelinessAttribute(): string { $d=$this->number_days_complied; if($d===null)return 'No Data'; if(!is_int($d))return $d; return match(true){$d<=5=>'Outstanding',$d===6=>'Very Satisfactory',$d===7=>'Satisfactory',$d<=13=>'Unsatisfactory',$d<=62=>'Poor',default=>'No Rating'}; }
    public function getSubmissionStatusAttribute(): string { if(!$this->date_accomplished&&!$this->date_received_penro)return 'No Activity Conducted'; if($this->date_received_penro)return 'Report Submitted'; return now(BusinessCalendarService::TIMEZONE)->startOfDay()->greaterThan(app(BusinessCalendarService::class)->addWorkingDays($this->date_accomplished, 7, $this->target_office ?? null)->startOfDay())?'Report Not Yet Submitted':'Ongoing Preparation at CENRO Level'; }
    public function getTotalDaysDelayedPenroAttribute(): int|string { if(!$this->date_received_penro||!$this->date_endorsed_regional)return 'Please Update Date Endorsed to Regional Office'; return (int)$this->date_received_penro->diffInDays($this->date_endorsed_regional); }
    public static function workingDaysAfterThrough(CarbonInterface $start, CarbonInterface $end, ?string $office = null): int { return app(BusinessCalendarService::class)->workingDaysBetween($start, $end, 'after_through', $office); }
}
