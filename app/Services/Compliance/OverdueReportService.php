<?php

namespace App\Services\Compliance;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ReportComplianceConfirmation;
use Carbon\CarbonImmutable;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use App\Services\Modules\ModuleMetadataResolver;
use App\Models\ModuleDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class OverdueReportService
{
    private const TIMEZONE = 'Asia/Manila';

    public function __construct(private readonly ConservationReportWorkflowRegistry $workflows, private readonly EngpReportWorkflowRegistry $engpWorkflows, private readonly ProtectedAttachmentService $attachments, private readonly RoutingStatusPresenter $statusPresenter, private readonly ModuleMetadataResolver $moduleResolver) {}

    /** @return array<class-string<Model>, array<string, mixed>> */
    public function sourceDefinitions(): array
    {
        return [
            ConservationReportSubmission::class => [
                'module' => 'Generic Conservation Report Workflows',
                'module_resolver' => fn (Model $source): string => $this->workflows->find((string) $source->getAttribute('workflow_key'))['label'] ?? 'Conservation Report',
                'submitted' => 'date_received_penro',
                'activity' => 'activity_name',
                'document' => 'document_type',
                'mov_label' => 'MOV',
                'mov' => fn (Model $source) => $source->getAttribute('mov_file_path'),
            ],
            EngpReportSubmission::class => [
                'module' => 'ENGP Report Submission Tracker',
                'module_resolver' => fn (Model $source): string => $this->engpWorkflows->find((string) $source->getAttribute('workflow_key'))['label'] ?? 'ENGP Report',
                'submitted' => 'date_received_penro',
                'target_office' => 'office',
                'activity' => 'activity_name',
                'document' => 'document_type',
                'mov_label' => 'MOV',
                'mov_required' => false,
                'mov' => fn (Model $source) => $source->getAttribute('mov_file_path') ?: $source->getAttribute('mov_external_url'),
                'query_column' => 'deadline_submission',
                'reporting_period' => fn (Model $source): ?string => $source->getAttribute('period_label'),
            ],
            BmsReportSubmission::class => ['module' => 'BMS Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            BamsReportSubmission::class => ['module' => 'BAMS Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            ImeaReportSubmission::class => ['module' => 'IMEA Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            ImeaFacilityMaintenanceReport::class => ['module' => 'IMEA Facility Maintenance Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            Aws::class => ['module' => 'AWS Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'Report File', 'mov' => fn (Model $source) => $source->getAttribute('report_file_path')],
            ManagementPlan::class => ['module' => 'Management Plan Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'Supporting Document', 'mov' => fn (Model $source) => $source->getAttribute('attachments')],
            IpafManagementReport::class => ['module' => 'Management of IPAF Report', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            IpafRevenueCollection::class => [
                'module' => 'IPAF Revenue Collection Report Submission Tracker',
                'submitted' => 'date_received_penro',
                'activity' => 'activity_name',
                'document' => 'document_type',
                'query_column' => 'deadline_submission',
                'mov_label' => 'Supporting Document',
                'mov' => fn (Model $source) => $source->getAttribute('mov_file_path'),
                'reporting_period' => static function (Model $source): ?string {
                    $month = (int) $source->getAttribute('reporting_month');
                    $year = (int) $source->getAttribute('reporting_year');

                    return $month >= 1 && $month <= 12 && $year >= 1
                        ? CarbonImmutable::create($year, $month, 1, 0, 0, 0, self::TIMEZONE)->format('F Y')
                        : null;
                },
            ],
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    public function destinationReferences(): Collection
    {
        $references = collect();
        $sources = $this->loadSourceModels(function ($query, array $definition): void {
            $query->whereNotNull($definition['query_column'] ?? 'date_accomplished');
        });
        foreach ($sources as $source) {
            $model = $source['model'];
            $definition = $source['definition'];
            $area = $model->relationLoaded('protectedArea') ? $model->getRelation('protectedArea') : null;
            $metadata = $this->moduleMetadata($model, $definition);
            $references->push(['protected_area_id' => $model->getAttribute('protected_area_id') ? (int) $model->getAttribute('protected_area_id') : null,
                'protected_area_name' => $area?->name, 'target_office' => trim((string) $model->getAttribute($definition['target_office'] ?? 'target_office')),
                'source_type' => $model::class, 'source_id' => (int) $model->getKey(), 'module_name' => $metadata['module_name'], 'program_area' => $metadata['program_area']]);
        }
        return $references;
    }

    /** @return Collection<int, OverdueReport> */
    public function overdueReports(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $records = collect();

        foreach ($this->loadSourceModels(function ($query, array $definition): void {
            $query->whereNotNull($definition['query_column'] ?? 'date_accomplished');
        }) as $source) {
            $normalized = $this->normalize($source['model'], $source['definition'], $today);
            if ($normalized !== null) {
                $records->push($normalized);
            }
        }

        return $records
            ->sortBy([['targetOffice', 'asc'], ['protectedAreaName', 'asc'], ['deadline', 'asc']])
            ->values();
    }

    /** @return Collection<int, OverdueReport> */
    public function dueSoonReports(int $days = 3, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $through = $today->addDays(max(0, $days));
        $records = collect();

        foreach ($this->loadSourceModels(function ($query, array $definition): void {
            $query->whereNotNull($definition['query_column'] ?? 'date_accomplished');
        }) as $source) {
            $model = $source['model'];
            $definition = $source['definition'];
                $deadlineValue = $model->getAttribute('deadline_submission');
                if (! $deadlineValue || $this->dateString($model->getAttribute($definition['submitted']))) continue;
                $deadline = CarbonImmutable::parse($deadlineValue, self::TIMEZONE)->startOfDay();
                if ($deadline->lessThan($today) || ! $deadline->isSameDay($today->addDays(max(0, $days)))) continue;

                $protectedArea = $model->relationLoaded('protectedArea') ? $model->getRelation('protectedArea') : null;
                $targetOffice = trim((string) $model->getAttribute($definition['target_office'] ?? 'target_office'));
                $metadata = $this->moduleMetadata($model, $definition);
                $module = $metadata['module_name'];
                $activity = trim((string) $model->getAttribute($definition['activity']));
                $document = trim((string) $model->getAttribute($definition['document']));
                $records->push(new OverdueReport(
                    sourceType: $model::class,
                    sourceId: (int) $model->getKey(),
                    module: $module,
                    protectedAreaId: $model->getAttribute('protected_area_id') ? (int) $model->getAttribute('protected_area_id') : null,
                    protectedAreaName: $protectedArea?->name ?: 'Protected Area not specified',
                    targetOffice: $targetOffice ?: ($protectedArea?->name ?: 'Unassigned office'),
                    activity: $activity !== '' ? $activity : $module,
                    documentType: $document !== '' ? $document : 'Report',
                    deadline: $deadline->toDateString(),
                    submitted: false,
                    recordsConfirmed: false,
                    daysOverdue: 0,
                    reportingPeriod: $this->reportingPeriod($model, $definition),
                    movRequired: $definition['mov_required'] ?? true,
                    movPresent: $this->movPresent($model),
                    movReference: $this->movReference($model, $definition),
                    movLabel: $definition['mov_label'] ?? 'MOV',
                    complianceIssue: 'Report Due Soon',
                    submissionStatus: $this->statusPresenter->status($model),
                    moduleName: $metadata['module_name'],
                    workflowKey: $metadata['workflow_key'],
                    programArea: $metadata['program_area'],
                ));
        }

        return $records->sortBy([['deadline', 'asc'], ['targetOffice', 'asc']])->values();
    }

    /** @return Collection<int, OverdueReport> */
    public function dueTodayReports(?CarbonImmutable $today = null): Collection
    {
        return $this->dueSoonReports(0, $today);
    }

    /** @return Collection<int, OverdueReport> */
    public function pendingMovReports(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $records = collect();

        foreach ($this->loadSourceModels(function ($query, array $definition): void {
            $query->whereNotNull($definition['query_column'] ?? 'date_accomplished');
        }) as $source) {
            $normalized = $this->normalize($source['model'], $source['definition'], $today, true);
            if ($normalized && $normalized->complianceIssue === 'MOV Not Yet Submitted'
                && CarbonImmutable::parse($normalized->deadline, self::TIMEZONE)->greaterThanOrEqualTo($today)) {
                $records->push($normalized);
            }
        }

        return $records
            ->sortBy([['targetOffice', 'asc'], ['protectedAreaName', 'asc'], ['deadline', 'asc']])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function pendingRecordsVerification(): Collection
    {
        $records = collect();
        $latestEventIds = ReportComplianceConfirmation::query()
            ->selectRaw('MAX(id) AS id')
            ->groupBy('source_type', 'source_id')
            ->pluck('id');
        $confirmedSourceIds = ReportComplianceConfirmation::query()
            ->whereIn('id', $latestEventIds)
            ->where('event_type', ReportComplianceConfirmation::EVENT_CONFIRMED)
            ->get(['source_type', 'source_id'])
            ->groupBy('source_type')
            ->map(fn (Collection $events): array => $events->pluck('source_id')->map(fn ($id): int => (int) $id)->all());

        $sources = $this->loadSourceModels(function ($query, array $definition, string $modelClass) use ($confirmedSourceIds): void {
            $query->whereNotNull($definition['submitted'])
                ->when($confirmedSourceIds->get($modelClass), fn ($query, array $ids) => $query->whereNotIn($query->getModel()->getKeyName(), $ids));
        });

        foreach ($sources as $source) {
            $records->push($this->recordsVerificationPayload($source['model'], $source['definition']));
        }

        return $records->sortBy([['target_office', 'asc'], ['protected_area_name', 'asc'], ['deadline', 'asc']])
            ->values();
    }

    public function findSource(string $sourceType, int $sourceId): ?Model
    {
        if (! array_key_exists($sourceType, $this->sourceDefinitions())) {
            return null;
        }

        return $this->withProtectedArea($sourceType::query(), $sourceType)->find($sourceId);
    }

    public function sourceIsSubmitted(Model $source): bool
    {
        $definition = $this->sourceDefinitions()[$source::class] ?? null;

        return $definition !== null && filled($source->getAttribute($definition['submitted']));
    }

    /** @return array<string, mixed> */
    public function confirmationSnapshot(Model $source): array
    {
        $definition = $this->sourceDefinitions()[$source::class] ?? null;
        if (! $definition) {
            throw new \InvalidArgumentException('The source is not enrolled in Compliance Alerts.');
        }

        if ($source::class !== EngpReportSubmission::class) {
            $source->loadMissing('protectedArea');
        }
        $payload = $this->recordsVerificationPayload($source, $definition);

        return collect($payload)->only([
            'source_type', 'source_id', 'module', 'protected_area_id', 'protected_area_name', 'target_office',
            'module_name', 'program_area', 'activity', 'document_type', 'reporting_period', 'deadline', 'submission_date', 'submission_status',
            'mov_required', 'mov_present', 'mov_reference', 'mov_label',
        ])->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function confirmationHistory(): Collection
    {
        $events = ReportComplianceConfirmation::query()
            ->with(['confirmedBy:id,name', 'revokedBy:id,name'])
            ->latest('id')
            ->get();
        $latestIds = $events
            ->unique(fn (ReportComplianceConfirmation $event): string => "{$event->source_type}:{$event->source_id}")
            ->pluck('id')
            ->flip();

        return $events->map(function (ReportComplianceConfirmation $event) use ($latestIds): array {
            $snapshot = $event->snapshot ?? [
                'module' => 'Unknown report source',
                'protected_area_name' => 'Source record no longer exists',
                'target_office' => 'Source record no longer exists',
                'activity' => 'Source record no longer exists',
                'document_type' => 'Unavailable',
                'reporting_period' => null,
                'deadline' => null,
                'submission_date' => null,
            ];

            return [
                'id' => $event->id,
                'source_type' => $event->source_type,
                'source_id' => (int) $event->source_id,
                'module' => $snapshot['module'] ?? 'Unknown report source',
                'module_name' => $snapshot['module_name'] ?? $snapshot['module'] ?? 'Unknown report source',
                'program_area' => $snapshot['program_area'] ?? null,
                'protected_area_name' => $snapshot['protected_area_name'] ?? 'Protected Area not specified',
                'target_office' => $snapshot['target_office'] ?? 'Unassigned office',
                'location_label' => $this->locationLabel($snapshot['protected_area_name'] ?? null, $snapshot['target_office'] ?? null),
                'activity' => $snapshot['activity'] ?? 'Report',
                'document_type' => $snapshot['document_type'] ?? 'Report',
                'reporting_period' => $snapshot['reporting_period'] ?? null,
                'deadline' => $snapshot['deadline'] ?? null,
                'submission_date' => $snapshot['submission_date'] ?? null,
                'confirmed_by' => $event->confirmedBy?->name ?: 'Unknown user',
                'confirmed_at' => $event->confirmed_at?->toIso8601String(),
                'remarks' => $event->remarks,
                'event_type' => $event->event_type,
                'status' => $event->event_type,
                'is_current' => $latestIds->has($event->id),
                'revoked_by' => $event->revokedBy?->name,
                'revoked_at' => $event->revoked_at?->toIso8601String(),
                'revocation_reason' => $event->revocation_reason,
                'original_confirmation_id' => $event->original_confirmation_id,
            ];
        })->values();
    }

    /** @param array<string, mixed> $definition */
    private function normalize(Model $model, array $definition, CarbonImmutable $today, bool $includePendingMov = false): ?OverdueReport
    {
        $deadlineValue = $model->getAttribute('deadline_submission');
        if (! $deadlineValue) {
            return null;
        }

        $deadline = CarbonImmutable::parse($deadlineValue, self::TIMEZONE)->startOfDay();
        if (! $deadline->lessThan($today) && ! $includePendingMov) {
            return null;
        }

        $submittedAt = $this->dateString($model->getAttribute($definition['submitted']));
        $submitted = $submittedAt !== null;
        $movReference = $this->movReference($model, $definition);
        $movPresent = $this->movPresent($model);
        $movRequired = $definition['mov_required'] ?? true;
        $issue = $submitted && $movRequired
            ? 'MOV Not Yet Submitted'
            : 'Report Not Yet Submitted';

        if ($submitted && ($movPresent || ! $movRequired)) {
            return null;
        }

        if (! $deadline->lessThan($today) && ! $submitted) {
            return null;
        }

        $protectedArea = $model->relationLoaded('protectedArea') ? $model->getRelation('protectedArea') : null;
        $targetOffice = trim((string) $model->getAttribute($definition['target_office'] ?? 'target_office'));
        $metadata = $this->moduleMetadata($model, $definition);
        $module = $metadata['module_name'];
        $activity = trim((string) $model->getAttribute($definition['activity']));
        $document = trim((string) $model->getAttribute($definition['document']));

        return new OverdueReport(
            sourceType: $model::class,
            sourceId: (int) $model->getKey(),
            module: $module,
            protectedAreaId: $model->getAttribute('protected_area_id') ? (int) $model->getAttribute('protected_area_id') : null,
            protectedAreaName: $protectedArea?->name ?: 'Protected Area not specified',
            targetOffice: $targetOffice ?: ($protectedArea?->name ?: 'Unassigned office'),
            activity: $activity !== '' ? $activity : $module,
            documentType: $document !== '' ? $document : 'Report',
            deadline: $deadline->toDateString(),
            submitted: $submitted,
            recordsConfirmed: false,
            daysOverdue: $deadline->lessThan($today) ? $deadline->diffInDays($today) : 0,
            movRequired: $movRequired,
            movPresent: $movPresent,
            movReference: $movReference,
            movLabel: $definition['mov_label'] ?? 'MOV',
            complianceIssue: $issue,
            submissionDate: $submittedAt,
            submissionStatus: $this->statusPresenter->status($model),
            reportingPeriod: $this->reportingPeriod($model, $definition),
            moduleName: $metadata['module_name'],
            workflowKey: $metadata['workflow_key'],
            programArea: $metadata['program_area'],
        );
    }

    /** @param array<string, mixed> $definition */
    private function movReference(Model $model, array $definition): string|array|null
    {
        $resolver = $definition['mov'] ?? null;
        $value = is_callable($resolver) ? $resolver($model) : null;

        if (is_array($value)) {
            return array_values(array_filter($value, function (mixed $attachment): bool {
                $path = is_string($attachment) ? $attachment : (is_array($attachment) ? ($attachment['path'] ?? null) : null);
                return is_string($path) && trim($path) !== '';
            }));
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function movPresent(Model $model): bool
    {
        if ($model instanceof EngpReportSubmission
            && strtolower((string) parse_url((string) $model->getAttribute('mov_external_url'), PHP_URL_SCHEME)) === 'https') {
            return true;
        }

        $source = match (true) {
            $model instanceof ConservationReportSubmission => ['conservation-report', 'mov'],
            $model instanceof EngpReportSubmission => ['engp-report', 'mov'],
            $model instanceof BmsReportSubmission => ['bms-report', 'mov'],
            $model instanceof BamsReportSubmission => ['bams-report', 'mov'],
            $model instanceof ImeaReportSubmission => ['imea-report', 'mov'],
            $model instanceof ImeaFacilityMaintenanceReport => ['imea-maintenance', 'mov'],
            $model instanceof Aws => ['aws', 'report_file'],
            $model instanceof ManagementPlan => ['management-plan', 'attachments'],
            $model instanceof IpafManagementReport => ['ipaf-management', 'mov'],
            $model instanceof IpafRevenueCollection => ['ipaf-revenue', 'mov'],
            default => null,
        };
        if ($source === null) {
            return false;
        }

        if ($source[1] === 'attachments') {
            $attachments = $model->getAttribute('attachments');
            if (! is_array($attachments)) {
                return false;
            }
            foreach (array_keys($attachments) as $key) {
                if ($this->attachments->descriptor($source[0], $model, (string) $key) !== null) {
                    return true;
                }
            }
            return false;
        }

        return $this->attachments->descriptor($source[0], $model, $source[1]) !== null;
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function recordsVerificationPayload(Model $model, array $definition): array
    {
        $protectedArea = $model->relationLoaded('protectedArea') ? $model->getRelation('protectedArea') : null;
        $targetOffice = trim((string) $model->getAttribute($definition['target_office'] ?? 'target_office'));
        $metadata = $this->moduleMetadata($model, $definition);
        $module = $metadata['module_name'];
        $deadline = $this->dateString($model->getAttribute('deadline_submission'));
        $submittedAt = $this->dateString($model->getAttribute($definition['submitted']));
        $activity = trim((string) $model->getAttribute($definition['activity']));
        $document = trim((string) $model->getAttribute($definition['document']));

        return [
            'source_type' => $model::class,
            'source_id' => (int) $model->getKey(),
            'module' => $module,
            'module_name' => $metadata['module_name'],
            'workflow_key' => $metadata['workflow_key'],
            'program_area' => $metadata['program_area'],
            'protected_area_id' => $model->getAttribute('protected_area_id') ? (int) $model->getAttribute('protected_area_id') : null,
            'protected_area_name' => $protectedArea?->name ?: 'Protected Area not specified',
            'target_office' => $targetOffice ?: ($protectedArea?->name ?: 'Unassigned office'),
            'activity' => $activity !== '' ? $activity : $module,
            'document_type' => $document !== '' ? $document : 'Report',
            'reporting_period' => $this->reportingPeriod($model, $definition),
            'deadline' => $deadline,
            'submission_date' => $submittedAt,
            'submission_status' => $this->statusPresenter->status($model),
            'submitted' => true,
            'records_confirmed' => false,
            'records_confirmed_at' => null,
            'records_confirmed_by' => null,
            'records_confirmation_remarks' => null,
            'mov_required' => $definition['mov_required'] ?? true,
            'mov_present' => $this->movPresent($model),
            'mov_reference' => null,
            'mov_label' => $definition['mov_label'] ?? 'MOV',
        ];
    }

    private function withProtectedArea($query, string $modelClass)
    {
        return $modelClass === EngpReportSubmission::class ? $query : $query->with('protectedArea');
    }

    /**
     * Load the source universe already requested by the caller, then prime
     * request-local module metadata before row normalization starts.
     *
     * @param callable $configure receives the query, definition, and model class
     * @return Collection<int, array{model: Model, definition: array<string,mixed>}>
     */
    private function loadSourceModels(callable $configure): Collection
    {
        $sources = collect();

        foreach ($this->sourceDefinitions() as $modelClass => $definition) {
            $query = $this->withProtectedArea($modelClass::query(), $modelClass);
            $configure($query, $definition, $modelClass);

            foreach ($query->get() as $model) {
                $sources->push(['model' => $model, 'definition' => $definition]);
            }
        }

        $this->moduleResolver->prime($sources->pluck('model'));

        return $sources;
    }

    private function dateString(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return is_object($value) && method_exists($value, 'toDateString')
            ? $value->toDateString()
            : CarbonImmutable::parse($value, self::TIMEZONE)->toDateString();
    }

    /** @param array<string, mixed> $definition */
    private function moduleLabel(Model $model, array $definition): string
    {
        return $this->moduleMetadata($model, $definition)['module_name'];
    }

    /** @param array<string, mixed> $definition @return array{module_name:string,workflow_key:?string,program_area:?string} */
    private function moduleMetadata(Model $model, array $definition): array
    {
        $module = $definition['module_resolver'] ?? $definition['module'];
        $fallback = is_callable($module) ? (string) $module($model) : (string) $module;

        return $this->moduleResolver->resolve($model, $fallback, $definition['program_area'] ?? null);
    }

    /** @param array<string, mixed> $definition */
    private function reportingPeriod(Model $model, array $definition): ?string
    {
        $resolver = $definition['reporting_period'] ?? null;

        return is_callable($resolver) ? $resolver($model) : null;
    }

    private function locationLabel(?string $protectedArea, ?string $targetOffice): string
    {
        $protectedArea = trim((string) $protectedArea);
        $targetOffice = trim((string) $targetOffice);
        $hasProtectedArea = $protectedArea !== '' && ! in_array(mb_strtolower($protectedArea), ['protected area not specified', 'source record no longer exists'], true);
        $hasOffice = $targetOffice !== '' && ! in_array(mb_strtolower($targetOffice), ['unassigned office', 'source record no longer exists'], true);

        return match (true) {
            $hasProtectedArea && $hasOffice => $protectedArea.' �w^~)�t '.$targetOffice,
            $hasProtectedArea => $protectedArea,
            $hasOffice => $targetOffice,
            default => 'Office / Protected Area not specified',
        };
    }
}
