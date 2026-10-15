<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PamoPambDraft extends Model
{
    protected $fillable = [
        'draft_uuid', 'start_idempotency_key', 'protected_area_id', 'pamo_office_id', 'pamo_office_name', 'originator_user_id',
        'created_by', 'updated_by', 'workflow_key', 'report_payload', 'mov_path',
        'mov_file_name', 'started_at', 'status', 'conservation_report_submission_id',
        'bms_report_submission_id', 'bams_report_submission_id', 'imea_report_submission_id',
        'imea_facility_maintenance_report_id', 'aws_report_id', 'ipaf_management_report_id', 'ipaf_revenue_collection_id',
        'forwarded_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return ['report_payload' => 'array', 'started_at' => 'datetime', 'forwarded_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'draft_uuid'; }

    public function protectedArea(): BelongsTo { return $this->belongsTo(ProtectedArea::class); }
    public function pamoOffice(): BelongsTo { return $this->belongsTo(OrganizationalOffice::class, 'pamo_office_id'); }
    public function originator(): BelongsTo { return $this->belongsTo(User::class, 'originator_user_id'); }
    public function sourceSubmission(): BelongsTo { return $this->belongsTo(ConservationReportSubmission::class, 'conservation_report_submission_id'); }
}
