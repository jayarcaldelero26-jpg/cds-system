<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PambMovReviewEvent extends Model
{
    protected $fillable = ['conservation_report_submission_id', 'event_key', 'remarks', 'recorded_by'];

    public function submission(): BelongsTo { return $this->belongsTo(ConservationReportSubmission::class, 'conservation_report_submission_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
