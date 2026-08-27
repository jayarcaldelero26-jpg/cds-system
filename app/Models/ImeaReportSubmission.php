<?php

namespace App\Models;

use App\Models\Concerns\HasStandardAReportCalculations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImeaReportSubmission extends Model
{
    use HasFactory, HasStandardAReportCalculations;

    protected $guarded = [];
    protected $appends = ['deadline_submission', 'number_days_complied', 'timeliness', 'submission_status', 'total_days_delayed_penro'];

    protected function casts(): array
    {
        return ['date_accomplished' => 'date:Y-m-d', 'date_report_released_cenro' => 'date:Y-m-d', 'date_received_penro' => 'date:Y-m-d', 'date_endorsed_regional' => 'date:Y-m-d'];
    }

    public function protectedArea(): BelongsTo { return $this->belongsTo(ProtectedArea::class); }
}
