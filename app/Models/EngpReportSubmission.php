<?php

namespace App\Models;

use App\Services\Engp\EngpReportWorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngpReportSubmission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['workflow_key', 'office', 'section_name', 'activity_name', 'document_type', 'reporting_year', 'period_key', 'period_label', 'deadline_submission', 'date_received_penro', 'mov_file_path', 'mov_external_url', 'remarks', 'created_by', 'updated_by'];
    protected $appends = ['days_complied', 'timeliness_rating', 'submission_status'];

    protected function casts(): array
    {
        return ['deadline_submission' => 'date:Y-m-d', 'date_received_penro' => 'date:Y-m-d'];
    }

    public function releaseEvents(): HasMany
    {
        return $this->hasMany(EngpReportReleaseEvent::class);
    }

    public function getDaysCompliedAttribute(): ?int
    {
        if (! $this->date_received_penro || ! $this->deadline_submission) {
            return null;
        }

        return CarbonImmutable::parse($this->date_received_penro)->diffInDays(CarbonImmutable::parse($this->deadline_submission), false);
    }

    public function getTimelinessRatingAttribute(): ?string
    {
        return $this->days_complied === null ? null : match (true) {
            $this->days_complied >= 3 => 'Outstanding',
            $this->days_complied === 2 => 'Very Satisfactory',
            $this->days_complied === 1 => 'Satisfactory',
            $this->days_complied >= -1 => 'Unsatisfactory',
            default => 'Poor',
        };
    }

    public function getSubmissionStatusAttribute(): string
    {
        if ($this->date_received_penro) {
            return 'Report Submitted';
        }
        return CarbonImmutable::now('Asia/Manila')->startOfDay()->greaterThan(CarbonImmutable::parse($this->deadline_submission))
            ? 'Report Not Yet Submitted'
            : 'Within Allowable Preparation Period';
    }

    public function workflow(): ?array
    {
        return app(EngpReportWorkflowRegistry::class)->find((string) $this->workflow_key);
    }
}
