<?php

namespace App\Services\Compliance;

final readonly class OverdueReport
{
    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $module,
        public ?int $protectedAreaId,
        public string $protectedAreaName,
        public string $targetOffice,
        public string $activity,
        public string $documentType,
        public string $deadline,
        public bool $submitted,
        public bool $recordsConfirmed,
        public int $daysOverdue,
        public ?string $recordsConfirmedAt = null,
        public ?string $recordsConfirmedBy = null,
        public ?string $recordsConfirmationRemarks = null,
        public bool $isTestFixture = false,
        public ?string $reportingPeriod = null,
        public bool $movRequired = true,
        public bool $movPresent = false,
        public string|array|null $movReference = null,
        public string $movLabel = 'MOV',
        public string $complianceIssue = 'Report Not Yet Submitted',
        public ?string $submissionDate = null,
        public string $submissionStatus = 'Report Not Yet Submitted',
        public ?string $moduleName = null,
        public ?string $workflowKey = null,
        public ?string $programArea = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'module' => $this->module,
            'module_name' => $this->moduleName ?: $this->module,
            'workflow_key' => $this->workflowKey,
            'program_area' => $this->programArea,
            'protected_area_id' => $this->protectedAreaId,
            'protected_area_name' => $this->protectedAreaName,
            'target_office' => $this->targetOffice,
            'activity' => $this->activity,
            'document_type' => $this->documentType,
            'reporting_period' => $this->reportingPeriod,
            'deadline' => $this->deadline,
            'submitted' => $this->submitted,
            'records_confirmed' => $this->recordsConfirmed,
            'records_confirmed_at' => $this->recordsConfirmedAt,
            'records_confirmed_by' => $this->recordsConfirmedBy,
            'records_confirmation_remarks' => $this->recordsConfirmationRemarks,
            'is_test_fixture' => $this->isTestFixture,
            'days_overdue' => $this->daysOverdue,
            'mov_required' => $this->movRequired,
            'mov_present' => $this->movPresent,
            'mov_reference' => null,
            'mov_label' => $this->movLabel,
            'compliance_issue' => $this->complianceIssue,
            'submission_date' => $this->submissionDate,
            'submission_status' => $this->submissionStatus,
        ];
    }
}
