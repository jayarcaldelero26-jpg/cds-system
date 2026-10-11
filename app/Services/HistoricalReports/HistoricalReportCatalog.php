<?php

namespace App\Services\HistoricalReports;

use App\Models\ManagementPlanType;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\DateConductedRangeService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionFormScopeService;

/** Active, permission-scoped choices for historical report entry. */
final class HistoricalReportCatalog
{
    public function __construct(
        private readonly ConservationReportWorkflowRegistry $conservation,
        private readonly EngpReportWorkflowRegistry $engp,
        private readonly OrganizationalAccessService $organization,
        private readonly SubmissionFormScopeService $formScope,
        private readonly DateConductedRangeService $dateRanges,
    ) {}

    /** @return array{workflows:list<array<string,mixed>>,protectedAreas:list<array<string,mixed>>,offices:list<array<string,mixed>>,managementPlanTypes:list<array<string,mixed>>} */
    public function for(User $user): array
    {
        $scope = $this->formScope->options($user);
        $areas = $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')
            ->with('supervisingOfficeAssignment.office:id,name,is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'pamo'])
            ->map(fn (ProtectedArea $area): array => [
                'id' => (int) $area->id,
                'name' => (string) $area->name,
                'short_name' => $area->short_name,
                'pamo' => $area->pamo,
                'supervising_office' => $this->organization->normalizeOffice(
                    $area->supervisingOfficeAssignment?->office?->name
                        ?: $this->organization->supervisingOfficeNameForProtectedArea((int) $area->id)
                ),
            ])->values()->all();

        $workflows = [];
        if ($user->can('technical-reports.create')) {
            foreach ($this->conservation->all() as $workflow) {
                $key = (string) ($workflow['key'] ?? '');
                if ($key === '') continue;
                $workflows[] = [
                    'id' => 'conservation:'.$key,
                    'source' => 'conservation',
                    'workflow_key' => $key,
                    'kind' => 'conservation',
                    'label' => (string) ($workflow['label'] ?? $key),
                    'activities' => array_values($workflow['activities'] ?? []),
                    'documents' => array_values($workflow['documents'] ?? []),
                    'activity_documents' => $workflow['activity_documents'] ?? [],
                    'periods' => array_values($workflow['periods'] ?? []),
                    'period_label' => (string) ($workflow['period_label'] ?? 'Reporting Period'),
                    'period_required' => (bool) ($workflow['period_required'] ?? false),
                    'date_conducted_required' => app(\App\Services\Conservation\PambComplianceCalculator::class)->isMeeting($key),
                    'date_accomplished_required' => ! app(\App\Services\Conservation\PambComplianceCalculator::class)->isMeeting($key),
                    'date_ranges' => $this->dateRanges->supportsWorkflow($key),
                    'protected_area_required' => true,
                ];
            }
        }

        if ($user->can('technical-reports.create') && $this->organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT)) {
            foreach ($this->engp->all() as $workflow) {
                $key = (string) ($workflow['key'] ?? '');
                if ($key === '') continue;
                $offices = array_values(array_filter(
                    $workflow['offices'] ?? [],
                    fn (string $office): bool => $this->organization->canUseDevelopmentOffice($user, $office),
                ));
                if ($offices === []) continue;
                $workflows[] = [
                    'id' => 'engp:'.$key,
                    'source' => 'engp',
                    'workflow_key' => $key,
                    'kind' => 'engp',
                    'label' => (string) ($workflow['label'] ?? $key),
                    'period_kind' => (string) ($workflow['period'] ?? 'monthly'),
                    'document_type' => (string) ($workflow['document'] ?? 'Report'),
                    'activity' => (string) ($workflow['activity'] ?? $workflow['label'] ?? $key),
                    'offices' => $offices,
                    'protected_area_required' => false,
                ];
            }
        }

        $this->addSimpleWorkflow($workflows, $user, 'bms', 'BMS Report', 'Biodiversity Monitoring System report', 'bms.create', 'pa');
        $this->addSimpleWorkflow($workflows, $user, 'bams', 'BAMS Report', 'Biodiversity Assessment and Monitoring System report', 'bams.create', 'pa');
        $this->addSimpleWorkflow($workflows, $user, 'imea', 'IMEA Report', 'Integrated Management Effectiveness Assessment report', 'imea.create', 'pa');
        $this->addSimpleWorkflow($workflows, $user, 'imea-maintenance', 'IMEA Facility Maintenance', 'IMEA facility maintenance report', 'imea.create', 'maintenance');
        $this->addSimpleWorkflow($workflows, $user, 'aws', 'AWS Report', 'Automated Weather Station report record', 'aws.create', 'aws');
        $this->addSimpleWorkflow($workflows, $user, 'ipaf-management', 'Management of IPAF', 'IPAF management report', 'technical-reports.create', 'ipaf-management');
        $this->addSimpleWorkflow($workflows, $user, 'revenue', 'Revenue Collection', 'IPAF revenue collection report', 'technical-reports.create', 'revenue');

        if ($user->can('management-plans.create')) {
            $workflows[] = [
                'id' => 'management-plans:management_plans',
                'source' => 'management-plans',
                'workflow_key' => 'management_plans',
                'kind' => 'management-plans',
                'label' => 'Management Plans',
                'protected_area_required' => true,
            ];
        }

        $authorizedAreaIds = array_fill_keys(array_column($areas, 'id'), true);
        $hasPaScope = $user->can('technical-reports.create')
            || $user->can('bms.create')
            || $user->can('bams.create')
            || $user->can('imea.create')
            || $user->can('aws.create')
            || $user->can('management-plans.create');
        if (! $hasPaScope) {
            $areas = [];
        } else {
            $workflows = array_values(array_filter($workflows, function (array $workflow) use ($authorizedAreaIds): bool {
                if (! ($workflow['protected_area_required'] ?? false)) return true;
                return $authorizedAreaIds !== [];
            }));
        }

        $managementPlanTypes = $user->can('management-plans.create')
            ? ManagementPlanType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (ManagementPlanType $type): array => ['id' => (int) $type->id, 'name' => (string) $type->name])->all()
            : [];

        usort($workflows, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return [
            'workflows' => $workflows,
            'protectedAreas' => $areas,
            'offices' => $scope['targetOffices'],
            'managementPlanTypes' => $managementPlanTypes,
        ];
    }

    private function addSimpleWorkflow(array &$workflows, User $user, string $source, string $label, string $description, string $ability, string $kind): void
    {
        if (! $user->can($ability)) return;
        $workflows[] = [
            'id' => $source.':'.$source,
            'source' => $source,
            'workflow_key' => $source,
            'kind' => $kind,
            'label' => $label,
            'description' => $description,
            'protected_area_required' => true,
        ];
    }
}
