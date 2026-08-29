<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngpReportReleaseEvent extends Model
{
    use HasFactory;

    protected $fillable = ['engp_report_submission_id', 'period_component', 'component_label', 'date_report_released_cenro'];
    protected function casts(): array { return ['date_report_released_cenro' => 'date:Y-m-d']; }
    public function submission(): BelongsTo { return $this->belongsTo(EngpReportSubmission::class, 'engp_report_submission_id'); }
}
