<?php

namespace App\Services\SubmissionTracking;

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
use App\Models\SubmissionRoutingCorrection;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\Modules\ModuleMetadataResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogService;

final class SubmissionTrackingService
{
    public const CENRO_RELEASE = 'cenro_release';
    public const PENRO_RECEIPT = 'penro_receipt';
    public const REGIONAL_ENDORSEMENT = 'regional_endorsement';

    public function __construct(private readonly ConservationReportWorkflowRegistry $workflows, private readonly EngpReportWorkflowRegistry $engpWorkflows, private readonly ProtectedAreaRoutingPolicy $routingPolicy, private readonly EdatsInAppNotificationService $notifications, private readonly ProtectedAttachmentService $attachments, private readonly RoutingStatusPresenter $statusPresenter, private readonly AuditLogService $auditLogs, private readonly ModuleMetadataResolver $moduleResolver) {}

    /** @return Collection<int, array<string, mixed>> */
    public function records(array $filters = []): Collection
    {
        $loaded = collect($this->sources())
            ->flatMap(function (array $source, string $key) {
                $query = $source['model']::query();
                if ($key !== 'engp') $query->with('protectedArea:id,name,short_name');
                if ($key === 'engp') $query->with('releaseEvents');
                if ($source['requires_date_accomplished'] ?? true) $query->whereNotNull('date_accomplished');
                return $query->get()->map(fn (Model $record) => ['record' => $record, 'key' => $key, 'source' => $source]);
            });

        $this->moduleResolver->prime($loaded->pluck('record'));
        $correctionCounts = $this->correctionCounts($loaded);

        return $loaded
            ->map(fn (array $item): array => $this->normalize($item['record'], $item['key'], $item['source'], $correctionCounts))
            ->filter(fn (array $record) => $this->matchesFilters($record, $filters))
            ->sortByDesc(fn (array $record) => $record['date_accomplished'] ?? '')
            ->values();
    }

    /** @return array{records: Collection<int,array<string,mixed>>, queues: array<string,Collection<int,array<string,mixed>>>, modules: list<string>} */
    public function snapshot(array $filters = []): array
    {
        $records = $this->records($filters);

        return [
            'records' => $records,
            'queues' => $this->queues($filters, $records),
            'modules' => $this->modules($records),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function queues(array $filters = [], ?Collection $snapshotRecords = null): array
    {
        $records = $snapshotRecords ?? $this->records($filters);

        return [
            self::CENRO_RELEASE => $records->where('stage', self::CENRO_RELEASE)->values(),
            self::PENRO_RECEIPT => $records->where('stage', self::PENRO_RECEIPT)->values(),
            self::REGIONAL_ENDORSEMENT => $records->where('stage', self::REGIONAL_ENDORSEMENT)->values(),
            'history' => $records->filter(fn (array $record) => $record['routing_complete'])
                ->sortByDesc(fn (array $record) => $record['completed_at'] ?? '')
                ->values(),
        ];
    }

    /** @return list<string> */
    public function modules(?Collection $snapshotRecords = null): array
    {
        return ($snapshotRecords ?? $this->records())->pluck('module')->unique()->sort()->values()->all();
    }

    public function transition(string $sourceKey, int $id, string $stage, string $date, ?int $userId): void
    {
        $source = $this->source($sourceKey);
        abort_unless($source, 404);
        $record = $source['model']::query()->findOrFail($id);
        $expectedStage = $this->stage($record);
        if ($expectedStage !== $stage) {
            throw ValidationException::withMessages(['date' => 'This report is no longer awaiting that workflow action.']);
        }

        $value = Carbon::parse($date)->toDateString();
        if ($sourceKey === 'engp') {
            if ($stage === self::REGIONAL_ENDORSEMENT) throw ValidationException::withMessages(['stage' => 'ENGP reports do not use Regional Endorsement.']);
            if ($stage === self::CENRO_RELEASE) {
                $components = $this->engpWorkflows->releaseComponents((string) $record->workflow_key, (int) $record->reporting_year, (string) $record->period_key);
                $existing = $record->releaseEvents()->pluck('period_component')->all();
                $component = collect($components)->first(fn (array $item): bool => ! in_array($item['key'], $existing, true));
                if (! $component) throw ValidationException::withMessages(['date' => 'All CENRO release components are already recorded.']);
                $record->releaseEvents()->create(['period_component' => $component['key'], 'component_label' => $component['label'], 'date_report_released_cenro' => $value]);
                $this->notifyTransition($sourceKey, $record, $source, $stage, $value);
                $this->auditTransition($sourceKey, $record, $source, $stage, $value);
                return;
            }
            $release = $record->releaseEvents()->orderByDesc('date_report_released_cenro')->value('date_report_released_cenro');
            if ($release && $value < Carbon::parse($release)->toDateString()) throw ValidationException::withMessages(['date' => 'PENRO receipt cannot be earlier than CENRO release.']);
            $record->update(['date_received_penro' => $value, ...($userId ? ['updated_by' => $userId] : [])]);
            $this->notifyTransition($sourceKey, $record, $source, $stage, $value);
            $this->auditTransition($sourceKey, $record, $source, $stage, $value);
            return;
        }
        $this->validateChronology($record, $stage, $value);
        $field = match ($stage) {
            self::CENRO_RELEASE => 'date_report_released_cenro',
            self::PENRO_RECEIPT => 'date_received_penro',
            self::REGIONAL_ENDORSEMENT => 'date_endorsed_regional',
            default => throw ValidationException::withMessages(['stage' => 'Unsupported workflow action.']),
        };

        $changes = [$field => $value];
        if ($userId && $record->getConnection()->getSchemaBuilder()->hasColumn($record->getTable(), 'updated_by')) {
            $changes['updated_by'] = $userId;
        }
        DB::transaction(fn () => $record->update($changes));
        $this->notifyTransition($sourceKey, $record, $source, $stage, $value);
        $this->auditTransition($sourceKey, $record, $source, $stage, $value);
    }

    /** @return array<string, mixed>|null */
    public function source(string $key): ?array
    {
        return $this->sources()[$key] ?? null;
    }

    /** @param array<string, mixed> $source */
    private function notifyTransition(string $sourceKey, Model $record, array $source, string $stage, string $date): void
    {
        if ($sourceKey !== 'engp') {
            $record->loadMissing('protectedArea:id,name');
        }

        $this->notifications->submissionTransition([
            'source' => $sourceKey,
            'source_id' => $record->getKey(),
            'module' => $this->moduleMetadata($record, $source)['module_name'],
            'target_office' => $record->getAttribute($source['target_office'] ?? 'target_office'),
            'protected_area' => $sourceKey === 'engp' ? null : $record->protectedArea?->name,
            'supports_regional_endorsement' => $source['supports_regional_endorsement'] ?? $sourceKey !== 'engp',
        ], $stage, $date);
    }

    private function auditTransition(string $sourceKey, Model $record, array $source, string $stage, string $date): void
    {
        $metadata = $this->moduleMetadata($record, $source);
        $this->auditLogs->record('submission_tracking', match ($stage) {
            self::CENRO_RELEASE => 'CENRO Release Recorded',
            self::PENRO_RECEIPT => 'PENRO Receipt Recorded',
            default => 'Regional Endorsement Recorded',
        }, $sourceKey, $record->getKey(), $metadata['module_name'], 'Recorded '.$stage.' for '.$sourceKey.' record #'.$record->getKey().'.', ['date' => $date, 'stage' => $stage, 'program_area' => $metadata['program_area']]);
    }

    /** @return array<string, array<string, mixed>> */
    private function sources(): array
    {
        return [
            'engp' => ['model' => EngpReportSubmission::class, 'module' => fn (Model $record) => $this->engpWorkflows->find((string) $record->workflow_key)['label'] ?? 'ENGP Report', 'target_office' => 'office', 'ability' => 'technical-reports.update', 'requires_date_accomplished' => false, 'supports_regional_endorsement' => false, 'url' => fn (Model $record) => route('engp-reports.index', $record->workflow_key), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('engp-reports.mov', [$record->workflow_key, $record]) : $this->safeExternalUrl($record->mov_external_url), 'mov_external' => fn (Model $record) => $this->safeExternalUrl($record->mov_external_url) !== null && empty($record->mov_file_path)],
            'conservation' => ['model' => ConservationReportSubmission::class, 'module' => fn (Model $record) => $this->workflows->find((string) $record->workflow_key)['label'] ?? 'Conservation Report', 'ability' => 'technical-reports.update', 'url' => fn (Model $record) => route('conservation-reports.index', $record->workflow_key), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('conservation-reports.mov', [$record->workflow_key, $record]) : null],
            'bms' => ['model' => BmsReportSubmission::class, 'module' => fn () => 'BMS Report', 'ability' => 'bms.update', 'url' => fn () => route('bms.index', ['tracker' => 1]), 'mov_url' => fn (Model $record) => $record->mov_file_path ? $this->attachments->url('bms-report', $record, 'mov') : null],
            'bams' => ['model' => BamsReportSubmission::class, 'module' => fn () => 'BAMS Report', 'ability' => 'bams.update', 'url' => fn () => route('bams.report-submissions.index'), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('bams.report-submissions.mov', $record) : null],
            'imea' => ['model' => ImeaReportSubmission::class, 'module' => fn () => 'IMEA Report', 'ability' => 'imea.update', 'url' => fn () => route('imea.report-submissions.index'), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('imea.report-submissions.mov', $record) : null],
            'aws' => ['model' => Aws::class, 'module' => fn () => 'AWS Report', 'ability' => 'aws.update', 'url' => fn () => route('aws.index'), 'mov_url' => fn (Model $record) => $record->report_file_path ? route('aws.report-file.show', $record) : null],
            'ipaf-management' => ['model' => IpafManagementReport::class, 'module' => fn () => 'Management of IPAF', 'ability' => 'technical-reports.update', 'url' => fn () => route('ipaf.index', ['ipaf_tab' => 'management']), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('ipaf.management.mov', $record) : null],
            'imea-maintenance' => ['model' => ImeaFacilityMaintenanceReport::class, 'module' => fn () => 'IMEA Facility Maintenance', 'ability' => 'imea.update', 'url' => fn () => route('imea.maintenance-reports.index'), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('imea.maintenance-reports.mov', $record) : null],
            'revenue' => ['model' => IpafRevenueCollection::class, 'module' => fn () => 'Revenue Collection', 'ability' => 'technical-reports.update', 'requires_date_accomplished' => false, 'url' => fn () => route('ipaf.index', ['ipaf_tab' => 'revenue']), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('ipaf.revenue.mov', $record) : null],
            'management-plans' => ['model' => ManagementPlan::class, 'module' => fn () => 'Management Plans', 'ability' => 'management-plans.update', 'url' => fn () => route('management-plans.index'), 'mov_url' => fn (Model $record) => data_get(collect($record->attachments ?? [])->first(), 'url')],
        ];
    }

    /** @param array<string, mixed> $source
     *  @return array<string, mixed> */
    private function normalize(Model $record, string $sourceKey, array $source, array $correctionCounts = []): array
    {
        $isEngp = $sourceKey === 'engp';
        $period = $isEngp
            ? $record->getAttribute('period_label')
            : ($record->getAttribute('reporting_period') ?: $record->getAttribute('semester'));
        if (! $period && $record->getAttribute('reporting_month')) {
            $period = Carbon::create()->month((int) $record->getAttribute('reporting_month'))->format('F').' '.$record->getAttribute('reporting_year');
        }
        $directPenro = ! $isEngp && $this->routingPolicy->isDirectPenro($record);
        $releaseDate = $isEngp ? $record->releaseEvents->pluck('date_report_released_cenro')->filter()->sort()->last() : ($record->getAttribute('date_report_released_cenro') ? Carbon::parse($record->getAttribute('date_report_released_cenro'))->toDateString() : null);
        $dates = $isEngp ? ['date_received_penro'] : ['date_accomplished', 'date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional'];
        $metadata = $this->moduleMetadata($record, $source);
        $data = [
            'source' => $sourceKey,
            'source_id' => $record->getKey(),
            'module' => $metadata['module_name'],
            'module_name' => $metadata['module_name'],
            'target_office' => $record->getAttribute($source['target_office'] ?? 'target_office'),
            'protected_area' => $isEngp ? null : $record->protectedArea?->name,
            'protected_area_id' => $record->getAttribute('protected_area_id'),
            'activity_name' => $record->getAttribute('activity_name') ?: $record->getAttribute('station_name'),
            'document_type' => $record->getAttribute('document_type') ?: $record->getAttribute('report_period_type'),
            'program' => $metadata['program_area'],
            'program_area' => $metadata['program_area'],
            'reporting_year' => $record->getAttribute('reporting_year'),
            'date_conducted' => $record->getAttribute('date_conducted'),
            'date_accomplished' => null,
            'reporting_period' => $period,
            'deadline_submission' => $record->getAttribute('deadline_submission'),
            'days_complied' => $record->getAttribute('days_complied') ?? $record->getAttribute('number_days_complied'),
            'submission_status' => $this->statusPresenter->status($record, $sourceKey),
            'timeliness' => $record->getAttribute($isEngp ? 'timeliness_rating' : 'timeliness'),
            'penro_delay_days' => $record->getAttribute('penro_delay') ?? $record->getAttribute('total_days_delayed_penro'),
            'mov_status' => ($record->getAttribute('mov_file_path') || $record->getAttribute('report_file_path') || $record->getAttribute('mov_external_url') || ! empty($record->getAttribute('attachments'))) ? 'Complete' : ($record->getAttribute('date_received_penro') ? 'MOV Not Yet Submitted' : 'Not Yet Available'),
            'mov_url' => isset($source['mov_url']) ? ($source['mov_url'])($record) : null,
            'mov_external' => isset($source['mov_external']) ? (bool) ($source['mov_external'])($record) : false,
            'source_url' => ($source['url'])($record),
            'submission_origin' => $directPenro ? 'PENRO' : 'CENRO',
            'cenro_release_applicable' => ! $directPenro,
        ];
        foreach ($dates as $field) {
            $value = $record->getAttribute($field);
            $data[$field] = $value ? Carbon::parse($value)->toDateString() : null;
        }
        $data['date_report_released_cenro'] = $releaseDate;
        $data['date_endorsed_regional'] = $isEngp ? null : ($data['date_endorsed_regional'] ?? null);
        $data['release_events'] = $isEngp ? $record->releaseEvents->map(fn ($event) => ['id' => $event->id, 'period_component' => $event->period_component, 'component_label' => $event->component_label, 'date_report_released_cenro' => $event->date_report_released_cenro?->toDateString()])->values()->all() : [];
        $data['routing_corrections_count'] = $correctionCounts[$sourceKey.':'.$record->getKey()] ?? 0;
        $data['stage'] = $this->stage($record);
        $data['routing_complete'] = $this->isRoutingComplete($record);
        $data['completed_at'] = $data['routing_complete'] ? $this->routingCompletedAt($record) : null;
        $data['can_transition'] = auth()->user()?->can($source['ability']) ?? false;
        return $data;
    }


    /** @param Collection<int,array{record: Model,key:string,source:array<string,mixed>}> $loaded @return array<string,int> */
    private function correctionCounts(Collection $loaded): array
    {
        $idsBySource = $loaded->groupBy('key')->map(fn (Collection $items): array => $items->pluck('record')->map(fn (Model $record): int => (int) $record->getKey())->all());
        if ($idsBySource->isEmpty()) return [];

        return SubmissionRoutingCorrection::query()
            ->where(function ($query) use ($idsBySource): void {
                foreach ($idsBySource as $source => $ids) {
                    $query->orWhere(fn ($inner) => $inner->where('source', $source)->whereIn('source_id', $ids));
                }
            })
            ->select('source', 'source_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('source', 'source_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->source.':'.$row->source_id => (int) $row->aggregate])
            ->all();
    }
    /** @param array<string, mixed> $source @return array{module_name:string,program_area:?string,workflow_key:?string} */
    private function moduleMetadata(Model $record, array $source): array
    {
        $fallback = isset($source['module']) && is_callable($source['module'])
            ? (string) ($source['module'])($record)
            : null;

        return $this->moduleResolver->resolve($record, $fallback, $source['program_area'] ?? null);
    }

    /**
     * Resolve completion from the same source-routing policy that determines
     * the next active queue. It intentionally does not treat intermediate
     * routing dates as completed workflow records.
     */
    public function isRoutingComplete(Model $record): bool
    {
        return $this->stage($record) === 'endorsed'
            && $this->routingCompletedAt($record) !== null;
    }

    private function routingCompletedAt(Model $record): ?string
    {
        $terminalStage = $this->terminalStage($record);
        $value = match ($terminalStage) {
            self::PENRO_RECEIPT => $record->getAttribute('date_received_penro'),
            self::REGIONAL_ENDORSEMENT => $record->getAttribute('date_endorsed_regional'),
            default => null,
        };

        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    private function terminalStage(Model $record): string
    {
        return $record instanceof EngpReportSubmission
            ? self::PENRO_RECEIPT
            : $this->routingPolicy->terminalStage($record);
    }

    private function stage(Model $record): string
    {
        return $this->statusPresenter->stage($record);
    }

    private function validateChronology(Model $record, string $stage, string $date): void
    {
        $release = $stage === self::CENRO_RELEASE ? $date : $this->date($record, 'date_report_released_cenro');
        $receipt = $stage === self::PENRO_RECEIPT ? $date : $this->date($record, 'date_received_penro');
        $endorsement = $stage === self::REGIONAL_ENDORSEMENT ? $date : $this->date($record, 'date_endorsed_regional');
        $errors = [];
        if ($release && $receipt && $release > $receipt) $errors['date'] = 'PENRO receipt cannot be earlier than CENRO release.';
        if ($receipt && $endorsement && $receipt > $endorsement) $errors['date'] = 'Regional endorsement cannot be earlier than PENRO receipt.';
        if ($release && $endorsement && $release > $endorsement) $errors['date'] = 'Regional endorsement cannot be earlier than CENRO release.';
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function date(Model $record, string $field): ?string
    {
        $value = $record->getAttribute($field);
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    /** @param array<string, mixed> $record */
    private function matchesFilters(array $record, array $filters): bool
    {
        if (($filters['module'] ?? '') && $record['module'] !== $filters['module']) return false;
        if (($filters['protected_area_id'] ?? '') && (string) $record['protected_area_id'] !== (string) $filters['protected_area_id']) return false;
        if (($filters['target_office'] ?? '') && $record['target_office'] !== $filters['target_office']) return false;
        if (($filters['reporting_period'] ?? '') && $record['reporting_period'] !== $filters['reporting_period']) return false;
        if (($filters['status'] ?? '') && $record['submission_status'] !== $filters['status']) return false;
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        return ! $search || str_contains(strtolower(implode(' ', [$record['module'], $record['target_office'], $record['protected_area'], $record['activity_name'], $record['document_type'], $record['reporting_period']])), $search);
    }
}
