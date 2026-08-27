<?php

namespace App\Services\Compliance;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ReportComplianceConfirmation;
use App\Models\TechnicalReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class OverdueReportService
{
    private const TIMEZONE = 'Asia/Manila';

    /** @return array<class-string<Model>, array<string, mixed>> */
    public function sourceDefinitions(): array
    {
        return [
            BmsReportSubmission::class => ['module' => 'BMS Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            BamsReportSubmission::class => ['module' => 'BAMS Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            ImeaReportSubmission::class => ['module' => 'IMEA Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
            TechnicalReport::class => ['module' => 'Technical Reports', 'submitted' => 'submission_date', 'activity' => 'activity_name', 'document' => 'report_type', 'mov_label' => 'Supporting Document', 'mov' => fn (Model $source) => $source->getAttribute('attachment')],
            Aws::class => ['module' => 'AWS Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'Report File', 'mov' => fn (Model $source) => $source->getAttribute('report_file_path')],
            ManagementPlan::class => ['module' => 'Management Plan Report Submission Tracker', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'Supporting Document', 'mov' => fn (Model $source) => $source->getAttribute('attachments')],
            ImeaFacilityMaintenanceReport::class => ['module' => 'IMEA Maintenance of Ecotourism Facilities Report', 'submitted' => 'date_received_penro', 'activity' => 'activity_name', 'document' => 'document_type', 'mov_label' => 'MOV', 'mov' => fn (Model $source) => $source->getAttribute('mov_file_path')],
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

    /** @return Collection<int, OverdueReport> */
    public function overdueReports(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $records = collect();

        foreach ($this->sourceDefinitions() as $modelClass => $definition) {
            $models = $modelClass::query()
                ->with('protectedArea')
                ->whereNotNull($definition['query_column'] ?? 'date_accomplished')
                ->get();
            foreach ($models as $model) {
                $normalized = $this->normalize($model, $definition, $today);
                if ($normalized !== null) {
                    $records->push($normalized);
                }
            }
        }

        return $records
            ->sortBy([['targetOffice', 'asc'], ['protectedAreaName', 'asc'], ['deadline', 'asc']])
            ->values();
    }

    /** @return Collection<int, OverdueReport> */
    public function pendingMovReports(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $records = collect();

        foreach ($this->sourceDefinitions() as $modelClass => $definition) {
            $models = $modelClass::query()
                ->with('protectedArea')
                ->whereNotNull($definition['query_column'] ?? 'date_accomplished')
                ->get();
            foreach ($models as $model) {
                $normalized = $this->normalize($model, $definition, $today, true);
                if ($normalized && $normalized->complianceIssue === 'MOV Not Yet Submitted'
                    && CarbonImmutable::parse($normalized->deadline, self::TIMEZONE)->greaterThanOrEqualTo($today)) {
                    $records->push($normalized);
                }
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

        foreach ($this->sourceDefinitions() as $modelClass => $definition) {
            $models = $modelClass::query()
                ->with('protectedArea')
                ->whereNotNull($definition['submitted'])
                ->when($confirmedSourceIds->get($modelClass), fn ($query, array $ids) => $query->whereNotIn($query->getModel()->getKeyName(), $ids))
                ->get();

            foreach ($models as $model) {
                $records->push($this->recordsVerificationPayload($model, $definition));
            }
        }

        return $records->sortBy([['target_office', 'asc'], ['protected_area_name', 'asc'], ['deadline', 'asc']])
            ->values();
    }

    public function findSource(string $sourceType, int $sourceId): ?Model
    {
        if (! array_key_exists($sourceType, $this->sourceDefinitions())) {
            return null;
        }

        return $sourceType::query()->with('protectedArea')->find($sourceId);
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

        $source->loadMissing('protectedArea');
        $payload = $this->recordsVerificationPayload($source, $definition);

        return collect($payload)->only([
            'source_type', 'source_id', 'module', 'protected_area_id', 'protected_area_name', 'target_office',
            'activity', 'document_type', 'reporting_period', 'deadline', 'submission_date', 'submission_status',
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
                'protected_area_name' => $snapshot['protected_area_name'] ?? 'Protected Area not specified',
                'target_office' => $snapshot['target_office'] ?? 'Unassigned office',
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
        $movPresent = $this->movPresent($movReference);
        $issue = $submitted
            ? 'MOV Not Yet Submitted'
            : 'Report Not Yet Submitted';

        if ($submitted && $movPresent) {
            return null;
        }

        if (! $deadline->lessThan($today) && ! $submitted) {
            return null;
        }

        $protectedArea = $model->getRelation('protectedArea');
        $activity = trim((string) $model->getAttribute($definition['activity']));
        $document = trim((string) $model->getAttribute($definition['document']));

        return new OverdueReport(
            sourceType: $model::class,
            sourceId: (int) $model->getKey(),
            module: $definition['module'],
            protectedAreaId: $model->getAttribute('protected_area_id') ? (int) $model->getAttribute('protected_area_id') : null,
            protectedAreaName: $protectedArea?->name ?: 'Protected Area not specified',
            targetOffice: trim((string) $model->getAttribute('target_office')) ?: ($protectedArea?->name ?: 'Unassigned office'),
            activity: $activity !== '' ? $activity : $definition['module'],
            documentType: $document !== '' ? $document : 'Report',
            deadline: $deadline->toDateString(),
            submitted: $submitted,
            recordsConfirmed: false,
            daysOverdue: $deadline->lessThan($today) ? $deadline->diffInDays($today) : 0,
            movRequired: true,
            movPresent: $movPresent,
            movReference: $movReference,
            movLabel: $definition['mov_label'] ?? 'MOV',
            complianceIssue: $issue,
            submissionDate: $submittedAt,
            reportingPeriod: $this->reportingPeriod($model, $definition),
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

    private function movPresent(string|array|null $reference): bool
    {
        if (is_string($reference)) {
            return Storage::disk('public')->exists($reference);
        }

        if (! is_array($reference)) {
            return false;
        }

        foreach ($reference as $attachment) {
            $path = is_string($attachment) ? $attachment : (is_array($attachment) ? ($attachment['path'] ?? null) : null);
            if (is_string($path) && Storage::disk('public')->exists($path)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function recordsVerificationPayload(Model $model, array $definition): array
    {
        $protectedArea = $model->getRelation('protectedArea');
        $deadline = $this->dateString($model->getAttribute('deadline_submission'));
        $submittedAt = $this->dateString($model->getAttribute($definition['submitted']));
        $activity = trim((string) $model->getAttribute($definition['activity']));
        $document = trim((string) $model->getAttribute($definition['document']));

        return [
            'source_type' => $model::class,
            'source_id' => (int) $model->getKey(),
            'module' => $definition['module'],
            'protected_area_id' => $model->getAttribute('protected_area_id') ? (int) $model->getAttribute('protected_area_id') : null,
            'protected_area_name' => $protectedArea?->name ?: 'Protected Area not specified',
            'target_office' => trim((string) $model->getAttribute('target_office')) ?: ($protectedArea?->name ?: 'Unassigned office'),
            'activity' => $activity !== '' ? $activity : $definition['module'],
            'document_type' => $document !== '' ? $document : 'Report',
            'reporting_period' => $this->reportingPeriod($model, $definition),
            'deadline' => $deadline,
            'submission_date' => $submittedAt,
            'submission_status' => 'Report Submitted',
            'submitted' => true,
            'records_confirmed' => false,
            'records_confirmed_at' => null,
            'records_confirmed_by' => null,
            'records_confirmation_remarks' => null,
            'mov_required' => true,
            'mov_present' => $this->movPresent($this->movReference($model, $definition)),
            'mov_reference' => $this->movReference($model, $definition),
            'mov_label' => $definition['mov_label'] ?? 'MOV',
        ];
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
    private function reportingPeriod(Model $model, array $definition): ?string
    {
        $resolver = $definition['reporting_period'] ?? null;

        return is_callable($resolver) ? $resolver($model) : null;
    }
}
