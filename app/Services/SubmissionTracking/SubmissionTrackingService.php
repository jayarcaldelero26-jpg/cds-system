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
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\AuditLog;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\BusinessCalendarService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Modules\ModuleMetadataResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Support\DatePresentationNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogService;
use App\Services\Reports\ReportTrackingNumberService;

final class SubmissionTrackingService
{
    public const CENRO_RELEASE = 'cenro_release';
    public const PENRO_RECEIPT = 'penro_receipt';
    public const REGIONAL_ENDORSEMENT = 'regional_endorsement';

    /** @var array<string, list<string>> */
    private array $tableColumns = [];

    public function __construct(private readonly ConservationReportWorkflowRegistry $workflows, private readonly EngpReportWorkflowRegistry $engpWorkflows, private readonly ProtectedAreaRoutingPolicy $routingPolicy, private readonly PambRoutingTimelineService $pambRouting, private readonly PambMovProcessingService $pambMov, private readonly PambSubmissionAccessService $pambAccess, private readonly ProtectedAttachmentService $attachments, private readonly RoutingAttachmentService $routingAttachments, private readonly RoutingStatusPresenter $statusPresenter, private readonly AuditLogService $auditLogs, private readonly ModuleMetadataResolver $moduleResolver, private readonly OrganizationalAccessService $organization, private readonly DocumentRoutingTransitionService $genericRouting, private readonly ReportTrackingNumberService $trackingNumbers) {}

    /** @return Collection<int, array<string, mixed>> */
    public function records(array $filters = [], ?int $limitPerSource = null, bool $assignTrackingNumbers = true): Collection
    {
        $sources = $this->sources();
        if (($filters['program'] ?? null) === 'conservation') {
            unset($sources['engp']);
        } elseif (($filters['program'] ?? null) === 'engp') {
            $sources = array_intersect_key($sources, ['engp' => true]);
        }

        $loaded = collect($sources)
            ->flatMap(function (array $source, string $key) use ($filters, $limitPerSource) {
                $query = $this->sourceQuery($key, $source, $filters, true);
                if ($limitPerSource !== null) $query->limit(max(1, $limitPerSource));
                return $query->get()->map(fn (Model $record) => ['record' => $record, 'key' => $key, 'source' => $source]);
            });

        $this->moduleResolver->prime($loaded->pluck('record'));
        $trackingNumbers = $assignTrackingNumbers
            ? $this->trackingNumbers->ensureFor($loaded->map(fn (array $item): array => ['record' => $item['record'], 'key' => $item['key']]))
            : $this->trackingNumbers->existingFor($loaded->map(fn (array $item): array => ['record' => $item['record'], 'key' => $item['key']]));
        $correctionCounts = $this->correctionCounts($loaded);

        $routingAudits = $this->routingAudits($loaded);
        $routingEvents = $this->genericRoutingEvents($loaded);

        return $loaded
            ->map(fn (array $item): array => $this->normalize($item['record'], $item['key'], $item['source'], $correctionCounts, $routingAudits[$item['key'].':'.$item['record']->getKey()] ?? collect(), $routingEvents[$item['key'].':'.$item['record']->getKey()] ?? collect(), $trackingNumbers))
            ->filter(function (array $record): bool {
                $user = auth()->user();
                if ($record['source'] !== 'conservation' || ! $user) return true;
                $model = ConservationReportSubmission::query()->with('protectedArea')->find($record['source_id']);
                return $model ? $this->pambAccess->canView($user, $model) : false;
            })
            ->filter(fn (array $record) => $this->matchesFilters($record, $filters))
            ->sortByDesc(fn (array $record) => $record['date_accomplished'] ?? $record['date_conducted'] ?? '')
            ->values();
    }

    /** Lightweight bounded search for global-search consumers. */
    public function search(string $term, int $limit = 5): Collection
    {
        return $this->records(['search' => $term], max(1, $limit));
    }

    /** @return array{records: Collection<int,array<string,mixed>>, queues: array<string,Collection<int,array<string,mixed>>>, modules: list<string>} */
    public function snapshot(array $filters = [], ?int $page = null, int $perPage = 25): array
    {
        if ($page === null) {
            $records = $this->records($filters);
            $pagination = null;
        } else {
            $page = max(1, $page);
            $perPage = max(1, min(100, $perPage));
            // Fetch one page beyond the requested window from each source so
            // the UI can determine whether another bounded page exists. The
            // expensive normalization/history work is never performed for the
            // complete cross-module dataset during an index request.
            $candidates = $this->records($filters, ($page + 1) * $perPage);
            $records = $candidates->forPage($page, $perPage)->values();
            $total = $this->countForFilters($filters);
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
                'to' => $total > 0 ? min($page * $perPage, $total) : null,
                'has_more' => $page < max(1, (int) ceil($total / $perPage)),
            ];
        }

        return [
            'records' => $records,
            'queues' => $this->queues($filters, $records),
            'modules' => $this->modules($records),
            ...($pagination === null ? [] : ['pagination' => $pagination]),
        ];
    }

    /** @return array{modules:list<string>,targetOffices:list<string>,periods:list<string>,statuses:list<string>,years:list<int>} */
    public function filterOptions(array $filters = []): array
    {
        $modules = collect();
        $offices = collect();
        $periods = collect();
        $years = collect([now()->year]);

        $sources = $this->sources();
        if (($filters['program'] ?? null) === 'conservation') unset($sources['engp']);
        if (($filters['program'] ?? null) === 'engp') $sources = array_intersect_key($sources, ['engp' => true]);

        foreach ($sources as $key => $source) {
            $query = $this->sourceQuery($key, $source, [], false);
            $model = $query->getModel();
            $schema = Schema::connection($model->getConnectionName());
            $table = $model->getTable();
            $officeColumn = $key === 'engp' ? 'office' : 'target_office';
            if ($this->columnExists($schema, $table, $officeColumn)) {
                $offices = $offices->merge($query->clone()->reorder()->whereNotNull($officeColumn)->distinct()->orderBy($officeColumn)->pluck($officeColumn));
            }
            $periodColumn = $key === 'engp' ? 'period_label' : ($this->columnExists($schema, $table, 'reporting_period') ? 'reporting_period' : ($this->columnExists($schema, $table, 'semester') ? 'semester' : null));
            if ($periodColumn) {
                $periods = $periods->merge($query->clone()->reorder()->whereNotNull($periodColumn)->distinct()->orderBy($periodColumn)->pluck($periodColumn));
            }
            if ($this->columnExists($schema, $table, 'reporting_year')) $years = $years->merge($query->clone()->reorder()->whereNotNull('reporting_year')->distinct()->orderByDesc('reporting_year')->pluck('reporting_year'));
            if ($this->columnExists($schema, $table, 'workflow_key')) {
                foreach ($query->clone()->reorder()->whereNotNull('workflow_key')->distinct()->orderBy('workflow_key')->pluck('workflow_key') as $workflowKey) {
                    $record = $model->newInstance(['workflow_key' => $workflowKey]);
                    $modules->push($source['module']($record));
                }
            } else {
                $modules->push($source['module']($model->newInstance()));
            }
        }

        return [
            'modules' => $modules->filter()->unique()->sort()->values()->all(),
            'targetOffices' => $offices->filter()->unique()->sort()->values()->all(),
            'periods' => $periods->filter()->unique()->sort()->values()->all(),
            'statuses' => [RoutingStatusPresenter::NO_ACTIVITY, RoutingStatusPresenter::PENDING_CENRO, RoutingStatusPresenter::PENDING_PENRO, RoutingStatusPresenter::PENDING_REGIONAL, RoutingStatusPresenter::COMPLETED],
            'years' => $years->map(fn ($year): int => (int) $year)->filter(fn (int $year): bool => $year >= 2000 && $year <= 2100)->unique()->sortDesc()->values()->all(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function queues(array $filters = [], ?Collection $snapshotRecords = null): array
    {
        $records = $snapshotRecords ?? $this->records($filters);
        $user = auth()->user();
        $category = $user ? $this->organization->effectiveCategory($user) : null;
        $conservation = $user && $this->organization->canAccessUnit($user, OrganizationalAccessService::CONSERVATION);
        $pambRecords = $records->filter(fn (array $record): bool => ($record['pamb_routing_applicable'] ?? false));
        $genericRecords = $records->filter(fn (array $record): bool => ! ($record['pamb_routing_applicable'] ?? false));

        $merge = fn (Collection $pamb, Collection $generic): Collection => $pamb->merge($generic)->unique(fn (array $record): string => $record['source'].':'.$record['source_id'])->values();
        $genericQueue = fn (string $queue): Collection => $this->genericQueue($genericRecords, $queue);
        $terminalHistory = $records->filter(fn (array $record): bool => (bool) ($record['routing_complete'] ?? false))
            ->sortByDesc(fn (array $record) => $record['completed_at'] ?? '')
            ->values();
        $processedHistory = $this->processedByMyOffice($records, $user);
        // Super Admin is a global read-only viewer. Its page must therefore
        // have discoverable lifecycle buckets even though it has no normal
        // workflow category and must never receive a normal routing action.
        // Terminal classification remains sourced from the same canonical
        // routing_complete flag used by every other queue.
        if ($user && $this->organization->isGlobal($user)) {
            $active = $records->reject(fn (array $record): bool => (bool) ($record['routing_complete'] ?? false))->values();
            $inTransit = $active->filter(fn (array $record): bool => filled(data_get($record, 'routing.in_transit_to'))
                || str_starts_with((string) data_get($record, 'routing.current_stage', ''), 'transit_')
                || str_contains(mb_strtolower((string) data_get($record, 'routing.current_status', '')), 'awaiting receipt'))->values();
            return [
                'active' => $active,
                'in_transit' => $inTransit,
                'history' => $records->filter(fn (array $record): bool => (bool) ($record['routing_complete'] ?? false))->sortByDesc(fn (array $record) => $record['completed_at'] ?? '')->values(),
            ];
        }

        if (($category === OrganizationalAccessService::PAMO || $this->pambAccess->isPamo($user))
            && in_array(OrganizationalAccessService::CONSERVATION, $this->organization->effectiveUnits($user), true)) {
            return [
                'for_submission' => $merge($records->filter(fn (array $record): bool => in_array(data_get($record, 'mov_processing.queue'), ['for_submission', 'for_review', 'for_release'], true)), $genericQueue('pamo_origin')),
                'needs_correction' => $records->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'needs_correction')->values(),
                'release_history' => $records->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'release_history')->values(),
            ];
        }
        if ($category === OrganizationalAccessService::CENRO_FOCAL) {
            $history = $terminalHistory;
            return [
                'for_submission' => $merge($pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'for_submission'), $genericQueue('cenro_focal')),
                'for_review' => $pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'for_review')->values(),
                'needs_correction' => $merge($pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'needs_correction'), $genericQueue('cenro_correction')),
                'for_release' => $pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'for_release')->values(),
                'history' => $history,
                    'processed' => $processedHistory,
                'release_history' => $history,
            ];
        }
        if ($category === OrganizationalAccessService::CENRO_CHIEF) {
            $history = $terminalHistory;
            return [
                'for_review' => $merge($pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'for_review'), $genericQueue('cenro_chief')),
                'needs_correction' => $pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'needs_correction')->values(),
                'history' => $history,
                    'processed' => $processedHistory,
                'release_history' => $history,
            ];
        }
        if ($category === OrganizationalAccessService::CENRO_RECORDS) {
            $history = $terminalHistory;
            return [
                'cenro_release' => $merge($pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'for_release'), $genericQueue('cenro_records')),
                'for_release' => $pambRecords->filter(fn (array $record): bool => data_get($record, 'mov_processing.queue') === 'for_release')->values(),
                'history' => $history,
                    'processed' => $processedHistory,
                'release_history' => $history,
            ];
        }
        if ($conservation && in_array($category, [OrganizationalAccessService::PENRO_FOCAL, OrganizationalAccessService::PENRO_CHIEF, OrganizationalAccessService::PENRO_RECORDS, OrganizationalAccessService::OFFICE_PENRO, OrganizationalAccessService::PENRO_TSD_CHIEF], true)) {
            $history = $terminalHistory;

            return match ($category) {
                OrganizationalAccessService::PENRO_RECORDS => [
                    'penro_receipt' => $merge($pambRecords->filter(fn (array $record): bool => $record['stage'] === self::PENRO_RECEIPT), $genericQueue('penro_receipt')),
                    'penro_records_routing' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO]), $genericQueue('penro_records_routing')),
                    'penro_records_final' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS, PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL]), $genericQueue('penro_records_final')),
                    'regional_endorsement' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::RELEASED_TO_REGIONAL]), $genericQueue('regional_endorsement')),
                    'history' => $history,
                    'processed' => $processedHistory,
                ],
                OrganizationalAccessService::OFFICE_PENRO => [
                    'office_initial_routing' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::RECEIVED_BY_PENRO, PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD]), $genericQueue('office_initial_routing')),
                    'office_final_verdict' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL]), $genericQueue('office_final_verdict')),
                    'history' => $history,
                    'processed' => $processedHistory,
                ],
                OrganizationalAccessService::PENRO_TSD_CHIEF => [
                    'tsd_routing' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::RECEIVED_BY_TSD, PambRoutingTimelineService::FORWARDED_TSD_TO_CDS]), $genericQueue('tsd_routing')),
                    'history' => $history,
                    'processed' => $processedHistory,
                ],
                OrganizationalAccessService::PENRO_FOCAL => [
                    'cds_processing' => $merge($pambRecords->filter(fn (array $record): bool => $this->pambCurrentStageKey($record) === PambRoutingTimelineService::RECEIVED_BY_CDS), $genericQueue('cds_processing')),
                    'cds_correction' => $merge($pambRecords->filter(fn (array $record): bool => str_starts_with((string) data_get(collect($record['routing_timeline'] ?? [])->firstWhere('status', 'current'), 'stage_key'), PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_')), $genericQueue('cds_correction')),
                    'history' => $history,
                    'processed' => $processedHistory,
                ],
                OrganizationalAccessService::PENRO_CHIEF => [
                    'cds_review' => $merge($this->pambQueue($pambRecords, [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO]), $genericQueue('cds_review')),
                    'history' => $history,
                    'processed' => $processedHistory,
                ],
                default => [
                    'penro_receipt' => $genericQueue('penro_receipt'),
                    'regional_endorsement' => $genericQueue('regional_endorsement'),
                    'history' => $history,
                    'processed' => $processedHistory,
                ],
            };
        }

        return [
            self::CENRO_RELEASE => $records->where('stage', self::CENRO_RELEASE)->values(),
            self::PENRO_RECEIPT => $records->where('stage', self::PENRO_RECEIPT)->values(),
            self::REGIONAL_ENDORSEMENT => $records->where('stage', self::REGIONAL_ENDORSEMENT)->values(),
            'history' => $records->filter(fn (array $record): bool => $record['routing_complete'])->sortByDesc(fn (array $record) => $record['completed_at'] ?? '')->values(),
        ];
    }

    /** @param Collection<int,array<string,mixed>> $records */
    /** @param Collection<int,array<string,mixed>> $records */
    private function cenroHistory(Collection $records): Collection
    {
        $downstream = [
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
            DocumentRoutingProfileRegistry::PENRO_RECORDS,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO,
            DocumentRoutingProfileRegistry::OFFICE_PENRO,
            DocumentRoutingProfileRegistry::TRANSIT_TSD,
            DocumentRoutingProfileRegistry::TSD,
            DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL,
            DocumentRoutingProfileRegistry::CDS_FOCAL,
            DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF,
            DocumentRoutingProfileRegistry::CDS_CHIEF,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN,
            DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL,
            DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL,
            DocumentRoutingProfileRegistry::RELEASED_REGIONAL,
        ];

        return $records->filter(function (array $record) use ($downstream): bool {
            if (($record['cenro_release_applicable'] ?? false) !== true) return false;
            if (filled($record['date_report_released_cenro'] ?? null) || filled($record['date_received_penro'] ?? null) || filled($record['date_endorsed_regional'] ?? null)) return true;
            if ((bool) data_get($record, 'pamb_routing_applicable') && data_get($record, 'mov_processing.queue') === 'release_history') return true;
            return in_array(data_get($record, 'routing.current_stage'), $downstream, true);
        })->sortByDesc(fn (array $record) => $record['completed_at'] ?? $record['date_endorsed_regional'] ?? $record['date_received_penro'] ?? $record['date_report_released_cenro'] ?? '')->values();
    }
    /** @param Collection<int,array<string,mixed>> $records */
    private function processedByMyOffice(Collection $records, ?\App\Models\User $user): Collection
    {
        if (! $user) return collect();

        $category = $this->organization->effectiveCategory($user);
        if (! in_array($category, $this->organization->operationalCategories(), true)) return collect();

        $office = $this->organization->normalizeOffice($user->office_designated);
        $cenroCategories = [
            OrganizationalAccessService::CENRO_RECORDS,
            OrganizationalAccessService::CENRO_CHIEF,
            OrganizationalAccessService::CENRO_FOCAL,
        ];

        return $records->filter(function (array $record) use ($category, $office, $cenroCategories): bool {
            if ((bool) ($record['routing_complete'] ?? false)) return false;

            $events = ($record['pamb_routing_applicable'] ?? false)
                ? collect($record['routing_timeline'] ?? [])
                : collect(data_get($record, 'routing.routing_history', []));

            return $events->contains(function (mixed $event) use ($category, $office, $cenroCategories, $record): bool {
                if (! is_array($event)) return false;
                if ($this->organization->normalizeCategory($event['actor_category'] ?? null) !== $category) return false;

                $actorOffice = $this->organization->normalizeOffice($event['actor_office'] ?? null);
                if ($actorOffice !== null) return $actorOffice === $office;

                return ! in_array($category, $cenroCategories, true)
                    || $this->organization->normalizeOffice($record['target_office'] ?? null) === $office;
            });
        })->sortByDesc(function (array $record): string {
            return data_get($record, 'routing.last_updated')
                ?? data_get($record, 'routing_summary.last_updated')
                ?? '';
        })->values();
    }

    private function outgoingByLatestOffice(Collection $records, ?\App\Models\User $user): Collection
    {
        if (! $user) return collect();

        $category = $this->organization->effectiveCategory($user);
        if (! in_array($category, $this->organization->operationalCategories(), true)) return collect();

        $office = $this->organization->normalizeOffice($user->office_designated);
        $cenroCategories = [OrganizationalAccessService::CENRO_RECORDS, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_FOCAL];

        return $records->filter(function (array $record) use ($category, $office, $cenroCategories): bool {
            if ((bool) ($record['routing_complete'] ?? false)) return false;
            $events = ($record['pamb_routing_applicable'] ?? false) ? collect($record['routing_timeline'] ?? []) : collect(data_get($record, 'routing.routing_history', []));
            $last = $events->filter(fn (mixed $event): bool => is_array($event) && filled($event['occurred_at'] ?? null))->sortBy(fn (array $event): string => (string) ($event['occurred_at'] ?? '').':'.str_pad((string) ($event['id'] ?? 0), 12, '0', STR_PAD_LEFT))->last();
            if (($record['pamb_routing_applicable'] ?? false) && data_get($record, 'routing.last_action_actor_category')) {
                $last = [
                    'actor_category' => data_get($record, 'routing.last_action_actor_category'),
                    'actor_office' => data_get($record, 'routing.last_action_actor_office'),
                    'stage_key' => data_get($record, 'routing.current_stage'),
                    'event_type' => data_get($record, 'routing.correction')
                        ? 'returned_for_correction'
                        : (str_contains(strtolower((string) data_get($record, 'routing.last_action.label')), 'forward') ? 'forwarded' : 'received'),
                ];
            }
            if (! is_array($last) || $this->organization->normalizeCategory($last['actor_category'] ?? null) !== $category) return false;
            if (($record['pamb_routing_applicable'] ?? false)
                && ($last['event_type'] ?? null) === 'received') return false;
            if (($record['pamb_routing_applicable'] ?? false)
                && data_get($record, 'routing.current_stage') === PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL) return false;
            $actorOffice = $this->organization->normalizeOffice($last['actor_office'] ?? null);
            return $actorOffice !== null ? $actorOffice === $office : (! in_array($category, $cenroCategories, true) || $this->organization->normalizeOffice($record['target_office'] ?? null) === $office);
        })->values();
    }

    private function genericQueue(Collection $records, string $queue): Collection
    {
        $keys = match ($queue) {
            'pamo_origin' => ['forward_from_pamo'],
            'cenro_focal' => ['forward_to_cenro_chief'],
            'cenro_correction' => ['receive_correction', 'forward_to_cenro_chief'],
            'cenro_chief' => ['receive_at_cenro_chief', 'forward_to_cenro_records'],
            'cenro_records' => ['receive_at_cenro_records', 'forward_to_penro_records'],
            'penro_receipt' => ['receive_at_penro_records'],
            'penro_records_routing' => ['forward_to_office_penro'],
            'office_initial_routing' => ['receive_at_office_penro', 'assign_to_tsd_chief'],
            'tsd_routing' => ['receive_at_tsd_chief', 'forward_to_cds_focal'],
            'cds_processing' => ['receive_at_cds_focal', 'forward_to_cds_chief'],
            'cds_correction' => ['receive_correction', 'forward_to_cds_chief'],
            'cds_review' => ['receive_at_cds_chief', 'recommend_to_office_penro'],
            'office_final_verdict' => ['receive_at_office_penro_final', 'approve_for_regional_release'],
            'penro_records_final' => ['receive_at_penro_records_final', 'release_to_regional'],
            'regional_endorsement' => ['release_to_regional'],
            default => [],
        };

        $correctionQueue = in_array($queue, ['cenro_correction', 'cds_correction'], true);
        return $records->filter(function (array $record) use ($keys, $correctionQueue): bool {
            if ((bool) data_get($record, 'routing.correction', false) !== $correctionQueue) return false;
            return collect(data_get($record, 'routing.actions', []))->pluck('key')->intersect($keys)->isNotEmpty();
        })->values();
    }

    /** @param Collection<int,array<string,mixed>> $records */
    private function pambQueue(Collection $records, array $stages): Collection
    {
        return $records->filter(fn (array $record): bool => in_array($this->pambCurrentStage($record), $stages, true))->values();
    }

    /** @return list<array{0:string,1:string}> */
    /** @return list<array<string,mixed>> */
    public function dashboardActionQueue(?\App\Models\User $user = null): array
    {
        $user ??= auth()->user();
        $routingOnly = [
            OrganizationalAccessService::CENRO_RECORDS,
            OrganizationalAccessService::PENRO_RECORDS,
            OrganizationalAccessService::OFFICE_PENRO,
            OrganizationalAccessService::PENRO_TSD_CHIEF,
            OrganizationalAccessService::PENRO_FOCAL,
            OrganizationalAccessService::PENRO_CHIEF,
        ];
        if (! $user || ! in_array($this->organization->effectiveCategory($user), $routingOnly, true)) return [];

        $queues = $this->queues([], $this->records());
        $rows = collect($queues)
            ->except(['history', 'release_history', 'processed'])
            ->flatten(1)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->unique(fn (array $row): string => ($row['source'] ?? '').':'.($row['source_id'] ?? ''))
            ->values();

        return $rows->map(function (array $row): array {
            $routingAction = data_get($row, 'routing.actions.0.action_label') ?: data_get($row, 'routing.next_expected_action');
            $flags = $row['pamb_action_flags'] ?? [];
            $requiredAction = $routingAction
                ?: (! empty($flags['can_receive']) ? 'Receive' : (! empty($flags['can_release']) ? 'Release to PENRO Records' : (! empty($flags['can_review']) ? 'Review' : (! empty($flags['can_submit']) ? 'Submit for Review' : null))));

            return [
                'source' => $row['source'] ?? null,
                'source_id' => $row['source_id'] ?? null,
                'protected_area' => $row['protected_area'] ?? null,
                'module' => $row['module'] ?? null,
                'activity_name' => $row['activity_name'] ?? $row['document_type'] ?? null,
                'reporting_period' => $row['reporting_period'] ?? null,
                'status' => data_get($row, 'routing.current_status') ?: ($row['submission_status'] ?? null),
                'responsible_office' => data_get($row, 'routing.responsible_office') ?: ($row['target_office'] ?? null),
                'required_action' => $requiredAction,
                'source_url' => route('submission-tracking.index', ['source' => $row['source'] ?? null, 'source_id' => $row['source_id'] ?? null]),
            ];
        })->filter(fn (array $row): bool => filled($row['required_action']))->values()->all();
    }
    public function queueTabs(?\App\Models\User $user = null): array
    {
        return [['incoming', 'Incoming'], ['outgoing', 'Outgoing'], ['history', 'History']];
    }
    /**
     * Project the canonical queues into the simplified user workspace.
     * Internal queue keys remain available to workflow services and legacy
     * monitoring consumers.
     *
     * @return array{incoming: Collection<int,array<string,mixed>>, outgoing: Collection<int,array<string,mixed>>, history: Collection<int,array<string,mixed>>}
     */
    public function workspaceQueues(array $filters = [], ?Collection $snapshotRecords = null): array
    {
        $queues = $this->queues($filters, $snapshotRecords);
        $key = static fn (array $row): string => ($row['source'] ?? '').':'.($row['source_id'] ?? '');

        $history = collect($queues)
            ->only(['history', 'release_history'])
            ->flatten(1)
            ->filter(fn (mixed $row): bool => is_array($row) && (bool) ($row['routing_complete'] ?? false))
            ->unique($key)
            ->sortByDesc(fn (array $row): string => data_get($row, 'completed_at')
                ?? data_get($row, 'routing.last_updated')
                ?? data_get($row, 'routing_summary.last_updated')
                ?? '')
            ->values();

        $incoming = ($snapshotRecords ?? $this->records($filters))
            ->filter(fn (mixed $row): bool => is_array($row) && ! ($row['routing_complete'] ?? false))
            ->filter(fn (array $row): bool => $this->isCurrentOperationalOwner($row, auth()->user()))
            ->unique($key)
            ->values();
        $queueMembership = [];
        foreach (collect($queues)->except(['history', 'release_history', 'processed', 'active', 'in_transit']) as $queueName => $queueRows) {
            foreach ($queueRows as $row) {
                if (is_array($row) && ! ($row['routing_complete'] ?? false)) {
                    $queueMembership[$key($row)][] = (string) $queueName;
                }
            }
        }

        $user = auth()->user();
        if ($user && $this->organization->isGlobal($user)) {
            return [
                'incoming' => collect($queues['active'] ?? [])->filter(fn (array $row): bool => ! ($row['routing_complete'] ?? false))->values(),
                'outgoing' => collect($queues['in_transit'] ?? [])
                    ->filter(fn (array $row): bool => ! ($row['routing_complete'] ?? false))
                    ->reject(fn (array $row): bool => collect($queues['active'] ?? [])->contains(fn (array $active): bool => $key($active) === $key($row)))
                    ->unique($key)
                    ->values(),
                'history' => $history,
            ];
        }

        $incoming = $incoming->map(fn (array $row): array => [
            ...$row,
            'incoming_action_category' => $this->incomingActionCategory($row, $queueMembership[$key($row)] ?? []),
        ])->values();

        $outgoing = $this->outgoingByLatestOffice($snapshotRecords ?? $this->records($filters), auth()->user())
            ->filter(fn (mixed $row): bool => is_array($row) && ! ($row['routing_complete'] ?? false))
            ->unique($key)
            ->reject(fn (array $row): bool => $incoming->contains(fn (array $active): bool => $key($active) === $key($row)))
            ->sortByDesc(fn (array $row): string => data_get($row, 'routing.last_updated')
                ?? data_get($row, 'routing_summary.last_updated')
                ?? '')
            ->values();

        return ['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history];
    }

    private function incomingActionCategory(array $row, array $queueNames = []): string
    {
        if ((bool) data_get($row, 'routing.correction', false)) return 'correction';
        $currentAction = collect(data_get($row, 'routing.actions', []))->first(fn (mixed $action): bool => is_array($action) && filled($action['key'] ?? null) && ! ($action['correction'] ?? false));
        $currentAction ??= collect(data_get($row, 'routing.actions', []))->first(fn (mixed $action): bool => is_array($action) && filled($action['key'] ?? null));
        if (! is_array($currentAction)) {
            $nextExpectedAction = (string) data_get($row, 'routing.next_expected_action', '');
            if ($nextExpectedAction !== '') {
                return $this->actionCategory('', $nextExpectedAction);
            }
            $currentAction = collect($row['routing_timeline'] ?? [])->first(fn (mixed $stage): bool => is_array($stage) && ($stage['status'] ?? null) === 'current');
        }

        if (is_array($currentAction)) {
            return $this->actionCategory((string) ($currentAction['key'] ?? $currentAction['stage_key'] ?? ''), (string) ($currentAction['action_label'] ?? ''));
        }

        {
            $flags = $row['pamb_action_flags'] ?? [];
            if (! empty($flags['can_release'])) return 'release';
            if (! empty($flags['can_submit']) || ! empty($flags['can_review']) || ! empty($flags['can_return_for_penro_correction']) || ! empty($flags['can_approve_for_regional_release'])) return 'decision';
            if (in_array('penro_receipt', $queueNames, true)) return 'receive';
            if (in_array('cenro_release', $queueNames, true) || in_array('regional_endorsement', $queueNames, true)) return 'release';
            return 'decision';
        }
    }

    private function actionCategory(string $key, string $label = ''): string
    {
        $key = strtolower($key);
        $label = strtolower($label);

        if (str_starts_with($key, 'receive_') || str_contains($key, 'received') || str_contains($key, 'receipt')) {
            return 'receive';
        }
        if (str_contains($key, 'approv') || str_contains($key, 'review') || str_contains($key, 'correction') || str_starts_with($key, 'return_')) return 'decision';
        if (str_starts_with($key, 'forward_') || str_starts_with($key, 'assign_') || str_starts_with($key, 'recommend_') || str_contains($key, 'forwarded')) return 'forward';
        if (str_contains($key, 'release')) return 'release';
        if (str_contains($label, 'receive') || str_contains($label, 'receipt')) return 'receive';
        if (str_contains($label, 'approv') || str_contains($label, 'review') || str_contains($label, 'correction') || str_contains($label, 'return')) return 'decision';
        if (str_contains($label, 'forward') || str_contains($label, 'assign') || str_contains($label, 'recommend')) return 'forward';
        if (str_contains($label, 'release')) return 'release';

        return 'decision';
    }

    private function isCurrentOperationalOwner(array $row, ?\App\Models\User $user): bool
    {
        if (! $user) return false;

        if ($this->pambAccess->isPamo($user)) {
            return ($row['pamb_routing_applicable'] ?? false)
                && ! ($row['routing_complete'] ?? false)
                && (bool) data_get($row, 'pamb_action_flags.can_submit');
        }

        $category = $this->organization->effectiveCategory($user);
        if (! in_array($category, $this->organization->operationalCategories(), true)) return false;
        if ($this->organization->normalizeCategory(data_get($row, 'routing.responsible_user_category')) !== $category) return false;

        if (! in_array($category, [OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_RECORDS], true)) return true;

        $actorOffice = $this->organization->normalizeOffice($user->office_designated);
        $responsibleOffice = $this->organization->normalizeOffice(data_get($row, 'routing.responsible_office'));
        $targetOffice = $this->organization->normalizeOffice(data_get($row, 'target_office'));
        return $responsibleOffice === $actorOffice || ($targetOffice !== null && $targetOffice === $actorOffice);
    }
    private function pambCurrentStageKey(array $record): ?string    {
        $current = collect($record['routing_timeline'] ?? [])->firstWhere('status', 'current');
        return (string) ($current['stage_key'] ?? $current['key'] ?? '') ?: null;
    }

    private function pambCurrentStage(array $record): ?string
    {
        return app(PambRoutingTimelineService::class)->canonicalStageKey((string) ($this->pambCurrentStageKey($record) ?? ''));
    }
    /** @return list<string> */
    public function modules(?Collection $snapshotRecords = null): array
    {
        return ($snapshotRecords ?? $this->records())->pluck('module')->unique()->sort()->values()->all();
    }

    public function usesGenericRouting(string $sourceKey, int $id): bool
    {
        $source = $this->source($sourceKey);
        if (! $source) return false;
        $record = $source['model']::query()->findOrFail($id);
        $this->genericRouting->assertCanView($record, $sourceKey, auth()->user());
        return $this->usesGenericRecord($sourceKey, $record);
    }

    /** @return list<string> */
    public function genericTransitionKeys(string $sourceKey, int $id): array
    {
        $source = $this->source($sourceKey);
        abort_unless($source, 404);
        $record = $source['model']::query()->findOrFail($id);
        $this->genericRouting->assertCanView($record, $sourceKey, auth()->user());
        abort_unless($this->usesGenericRecord($sourceKey, $record), 422);
        return $this->genericRouting->actionKeys($record, $sourceKey, auth()->user());
    }

    public function transition(string $sourceKey, int $id, string $stage, ?string $date, ?int $userId, ?string $remarks = null, ?string $correctionReasonKey = null, ?string $correctionDetail = null): DocumentRoutingEvent|PambRoutingEvent|null
    {
        $source = $this->source($sourceKey);
        abort_unless($source, 404);
        $recordQuery = $source['model']::query();
        if ($sourceKey === 'conservation') $recordQuery->with('protectedArea');
        $record = $recordQuery->findOrFail($id);
        if ($sourceKey === 'engp' && $user = auth()->user()) {
            abort_unless($this->organization->canViewDevelopmentRecord($user, $record), 403);
        }
        if ($this->usesGenericRecord($sourceKey, $record)) {
            return $this->genericRouting->transition($record, $sourceKey, $stage, $userId, $remarks, $correctionReasonKey, $correctionDetail);
        }
        if ($record instanceof ConservationReportSubmission && ($user = auth()->user())) {
            abort_unless($this->pambAccess->canView($user, $record), 403);
            abort_unless($this->pambAccess->canPerformCanonical($user, $record, $stage), 403);
            if ($stage === self::CENRO_RELEASE && $this->pambAccess->isCenro($user) && ! $this->routingPolicy->isDirectPenro($record)) {
                abort_unless($this->pambAccess->canPerform($user, 'release'), 403);
                abort_unless(app(PambMovProcessingService::class)->status($record) === PambMovProcessingService::READY_FOR_RELEASE, 422);
            }
        }
        $expectedStage = $this->stage($record);
        if ($expectedStage !== $stage) {
            throw ValidationException::withMessages(['date' => 'This report is no longer awaiting that workflow action.']);
        }

        $value = Carbon::parse($date)->toDateString();
        $this->validateChronology($record, $stage, $value);
        $field = match ($stage) {
            self::CENRO_RELEASE => 'date_report_released_cenro',
            self::PENRO_RECEIPT => 'date_received_penro',
            self::REGIONAL_ENDORSEMENT => 'date_endorsed_regional',
            default => throw ValidationException::withMessages(['stage' => 'Unsupported workflow action.']),
        };

        $changes = [$field => $value];
        if ($userId && $this->columnExists($record->getConnection()->getSchemaBuilder(), $record->getTable(), 'updated_by')) {
            $changes['updated_by'] = $userId;
        }
        $actionAt = CarbonImmutable::now(BusinessCalendarService::TIMEZONE);
        $canonicalEvent = DB::transaction(function () use ($record, $changes, $stage, $value, $userId, $actionAt): ?PambRoutingEvent {
            $record->update($changes);
            if (! $record instanceof ConservationReportSubmission || ! $this->pambRouting->applies($record)) return null;

            $canonicalEvent = $this->pambRouting->recordCanonical($record, $stage, $value, $userId, $actionAt);
            if ($stage === self::PENRO_RECEIPT) {
                // Ordinary PENRO Records receipt is an acknowledgement plus an
                // immediate handoff to the Office of the PENRO. Keep both
                // immutable events in the same transaction so ownership cannot
                // be left between the two stages.
                $this->pambRouting->record(
                    $record->fresh(),
                    PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
                    $actionAt->toDateTimeString(),
                    $userId,
                );
            }

            return $canonicalEvent;
        });
        try {
            $this->auditTransition($sourceKey, $record, $source, $stage, $value, $userId);
        } catch (\Throwable $exception) {
            report($exception);
        }
        return $canonicalEvent;
    }

    public function transitionPambAsOverride(ConservationReportSubmission $record, string $stage, int $userId): void
    {
        if (! $this->pambRouting->applies($record)) {
            throw ValidationException::withMessages(['stage' => 'This record does not use PAMB routing.']);
        }
        if (! in_array($stage, [self::CENRO_RELEASE, self::PENRO_RECEIPT, self::REGIONAL_ENDORSEMENT], true)) {
            throw ValidationException::withMessages(['stage' => 'This PAMB action is not a canonical override action.']);
        }

        DB::transaction(function () use ($record, $stage, $userId): void {
            $locked = ConservationReportSubmission::query()->lockForUpdate()->findOrFail($record->getKey());
            if ($this->stage($locked) !== $stage) {
                throw ValidationException::withMessages(['stage' => 'This PAMB record is no longer awaiting that action.']);
            }
            $value = Carbon::now(BusinessCalendarService::TIMEZONE)->toDateString();
            $this->validateChronology($locked, $stage, $value);
            $field = match ($stage) {
                self::CENRO_RELEASE => 'date_report_released_cenro',
                self::PENRO_RECEIPT => 'date_received_penro',
                self::REGIONAL_ENDORSEMENT => 'date_endorsed_regional',
            };
            $locked->update([$field => $value, ...($this->columnExists($locked->getConnection()->getSchemaBuilder(), $locked->getTable(), 'updated_by') ? ['updated_by' => $userId] : [])]);
            $source = $this->source('conservation');
            try {
                $this->auditTransition('conservation', $locked, $source, $stage, $value, $userId);
        } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
    public function recordInternalRouting(string $sourceKey, int $id, string $stageKey, string $occurredAt, ?int $userId = null, ?string $remarks = null): \App\Models\PambRoutingEvent
    {
        if ($sourceKey !== 'conservation') {
            throw ValidationException::withMessages(['stage' => 'Detailed internal routing is limited to PAMB workflows.']);
        }

        $record = ConservationReportSubmission::query()->findOrFail($id);
        return $this->pambRouting->record($record, $stageKey, $occurredAt, $userId, $remarks);
    }

    /** @return array<string, mixed>|null */
    public function source(string $key): ?array
    {
        return $this->sources()[$key] ?? null;
    }

    /** Build one authorization-first query for a registered active source. */
    private function sourceQuery(string $key, array $source, array $filters = [], bool $withRelations = true)
    {
        $query = $source['model']::query();
        $model = $query->getModel();
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();

        if ($withRelations) {
            if ($key !== 'engp') $query->with('protectedArea:id,name,short_name');
            if ($key === 'conservation') $query->with(['routingEvents.recordedBy', 'movReviewEvents.recordedBy']);
            if ($key === 'engp') $query->with('releaseEvents');
        }
        if ($key === 'conservation') {
            $query->where(fn ($candidate) => $candidate
                ->where(fn ($meeting) => $meeting->whereIn('workflow_key', PambComplianceCalculator::MEETING_WORKFLOWS)->whereNotNull('date_conducted'))
                ->orWhere(fn ($other) => $other->whereNotNull('date_accomplished')->where(fn ($workflow) => $workflow->whereNotIn('workflow_key', PambComplianceCalculator::MEETING_WORKFLOWS)->orWhereNull('workflow_key'))));
        }
        // AWS observations are a separate analytics domain. Only report rows
        // in the canonical aws table participate in document routing.
        if ($key === 'aws') {
            $query->whereNull($table.'.timestamps');
        }
        if ($user = auth()->user()) {
            if ($key === 'conservation') $query = $this->pambAccess->scopeQuery($query, $user);
            elseif ($key === 'engp') $query = $this->organization->scopeDevelopmentQuery($query, $user);
            elseif ($this->hasProtectedAreaColumn($source['model'])) $query = $this->organization->scopeProtectedAreaQuery($query, $user);
        }
        $this->applyDatabaseFilters($query, $key, $filters);
        if ($key !== 'conservation' && $key !== 'engp' && ($source['requires_date_accomplished'] ?? true)) $query->whereNotNull('date_accomplished');

        $hasAccomplished = $this->columnExists($schema, $table, 'date_accomplished');
        $hasConducted = $this->columnExists($schema, $table, 'date_conducted');
        if ($hasAccomplished || $hasConducted) {
            $dateExpression = $hasAccomplished && $hasConducted
                ? 'COALESCE('.$table.'.date_accomplished, '.$table.'.date_conducted)'
                : ($hasAccomplished ? $table.'.date_accomplished' : $table.'.date_conducted');
            // Match the normalized collection sort: dated records first,
            // then null-date records in deterministic source-id order.
            $query->orderByRaw('CASE WHEN '.$dateExpression.' IS NULL THEN 1 ELSE 0 END ASC')
                ->orderByRaw($dateExpression.' DESC');
        }
        $query->orderBy($table.'.id');
        return $query;
    }

    private function countForFilters(array $filters): int
    {
        $sources = $this->sources();
        if (($filters['program'] ?? null) === 'conservation') unset($sources['engp']);
        if (($filters['program'] ?? null) === 'engp') $sources = array_intersect_key($sources, ['engp' => true]);
        return collect($sources)->sum(function (array $source, string $key) use ($filters): int {
            return (int) $this->sourceQuery($key, $source, $filters, false)->count();
        });
    }

    private function sourceModuleLabel(string $sourceKey, string $modelClass, string $workflowKey): string
    {
        $record = new $modelClass(['workflow_key' => $workflowKey]);
        return match ($sourceKey) {
            'engp' => (string) ($this->engpWorkflows->find($workflowKey)['label'] ?? 'ENGP Report'),
            'conservation' => (string) ($this->workflows->find($workflowKey)['label'] ?? 'Conservation Report'),
            default => (string) (($this->sources()[$sourceKey]['module'])($record) ?? 'Report'),
        };
    }

    private function auditTransition(string $sourceKey, Model $record, array $source, string $stage, string $date, ?int $userId = null): void
    {
        $metadata = $this->moduleMetadata($record, $source);
        $this->auditLogs->record('submission_tracking', match ($stage) {
            self::CENRO_RELEASE => 'CENRO Release Monitoring Event Recorded',
            self::PENRO_RECEIPT => 'PENRO Receipt Monitoring Event Recorded',
            default => 'Regional Endorsement Monitoring Event Recorded',
        }, $sourceKey, $record->getKey(), $metadata['module_name'], 'Recorded '.$stage.' for '.$sourceKey.' record #'.$record->getKey().'.', ['date' => $date, 'stage' => $stage, 'program_area' => $metadata['program_area']], $userId);
    }

    /** @return array<string, array<string, mixed>> */
    private function sources(): array
    {
        return [
            'engp' => ['model' => EngpReportSubmission::class, 'module' => fn (Model $record) => $this->engpWorkflows->find((string) $record->workflow_key)['label'] ?? 'ENGP Report', 'target_office' => 'office', 'ability' => 'technical-reports.update', 'requires_date_accomplished' => false, 'supports_regional_endorsement' => false, 'url' => fn (Model $record) => route('engp-reports.index', $record->workflow_key), 'mov_url' => fn (Model $record) => $record->mov_file_path ? route('engp-reports.mov', [$record->workflow_key, $record]) : $this->safeExternalUrl($record->mov_external_url), 'mov_external' => fn (Model $record) => $this->safeExternalUrl($record->mov_external_url) !== null && empty($record->mov_file_path)],
            'conservation' => ['model' => ConservationReportSubmission::class, 'module' => fn (Model $record) => $this->workflows->find((string) $record->workflow_key)['label'] ?? 'Conservation Report', 'ability' => 'technical-reports.update', 'url' => fn (Model $record) => route('conservation-reports.index', $record->workflow_key), 'mov_url' => fn (Model $record) => $record->mov_file_path ? $this->attachments->url('conservation-report', $record, 'mov') : null],
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
    private function normalize(Model $record, string $sourceKey, array $source, array $correctionCounts = [], Collection $routingAudits = new Collection, Collection $routingEvents = new Collection, array $trackingNumbers = []): array
    {
        $isEngp = $sourceKey === 'engp';
        $period = $isEngp
            ? $record->getAttribute('period_label')
            : ($record->getAttribute('reporting_period') ?: ($record->getAttribute('semester') ?: $record->getAttribute('quarter')));
        if (! $period && $record->getAttribute('reporting_month')) {
            $period = Carbon::create()->month((int) $record->getAttribute('reporting_month'))->format('F').' '.$record->getAttribute('reporting_year');
        }
        $directPenro = ! $isEngp && $this->routingPolicy->isDirectPenro($record);
        $releaseDate = $isEngp ? $record->releaseEvents->map(fn (Model $event): ?string => DatePresentationNormalizer::toDateString($event->getRawOriginal('date_report_released_cenro')))->filter()->sort()->last() : $this->date($record, 'date_report_released_cenro');
        $dates = $isEngp
            ? ['date_received_penro']
            : ($sourceKey === 'conservation'
                ? ['date_conducted', 'date_accomplished', 'date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional']
                : ['date_accomplished', 'date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional']);
        $metadata = $this->moduleMetadata($record, $source);
        $reportingYear = $record->getAttribute('reporting_year');
        if (! $reportingYear) {
            $yearDate = $record->getAttribute('date_accomplished') ?: $record->getAttribute('date_conducted');
            $reportingYear = $yearDate ? Carbon::parse($yearDate)->year : null;
        }
        $data = [
            'source' => $sourceKey,
            'source_id' => $record->getKey(),
            'tracking_number' => $trackingNumbers[$sourceKey.':'.$record->getKey()] ?? null,
            'workflow_key' => $record->getAttribute('workflow_key') ?: match ($sourceKey) {
                'bms' => 'bms',
                'bams' => 'bams',
                'imea' => 'imea',
                'imea-maintenance' => 'imea_facility_maintenance',
                'aws' => 'automated_weather_station',
                'ipaf-management' => 'ipaf_management',
                'revenue' => 'revenue_collection',
                'management-plans' => 'management_plans',
                default => null,
            },
            'module' => $metadata['module_name'],
            'module_name' => $metadata['module_name'],
            'target_office' => $record->getAttribute($source['target_office'] ?? 'target_office'),
            'protected_area' => $isEngp ? null : $record->protectedArea?->name,
            'protected_area_id' => $record->getAttribute('protected_area_id'),
            'activity_name' => $record->getAttribute('activity_name') ?: $record->getAttribute('station_name'),
            'document_type' => $record->getAttribute('document_type') ?: $record->getAttribute('report_period_type'),
            'program' => $metadata['program_area'],
            'program_area' => $metadata['program_area'],
            'reporting_year' => $reportingYear,
            'date_conducted' => $this->text($record, 'date_conducted'),
            'date_accomplished' => null,
            'reporting_period' => $period,
            'period_key' => $record->getAttribute('period_key'),
            'deadline_submission' => $record->getAttribute('deadline_submission'),
            'days_complied' => $record->getAttribute('days_complied') ?? $record->getAttribute('number_days_complied'),
            'submission_status' => $this->statusPresenter->status($record, $sourceKey),
            'timeliness' => $record->getAttribute($isEngp ? 'timeliness_rating' : 'timeliness'),
            'penro_delay_days' => $record->getAttribute('penro_delay') ?? $record->getAttribute('total_days_delayed_penro'),
            'mov_status' => ($record->getAttribute('mov_file_path') || $record->getAttribute('report_file_path') || $record->getAttribute('mov_external_url') || ! empty($record->getAttribute('attachments'))) ? 'Complete' : ($record->getAttribute('date_received_penro') ? 'MOV Not Yet Submitted' : 'Not Yet Available'),
            'mov_url' => isset($source['mov_url']) ? ($source['mov_url'])($record) : null,
            'mov_attachment' => $sourceKey === 'conservation'
                ? $this->attachments->descriptor('conservation-report', $record, 'mov')
                : null,
            'mov_external' => isset($source['mov_external']) ? (bool) ($source['mov_external'])($record) : false,
            'source_url' => ($source['url'])($record),
            'submission_origin' => $directPenro ? 'PENRO' : 'CENRO',
            'cenro_release_applicable' => ! $directPenro,
        ];
        foreach ($dates as $field) {
            $data[$field] = $field === 'date_conducted' ? $this->text($record, $field) : $this->date($record, $field);
        }
        $data['date_report_released_cenro'] = $releaseDate;
        $data['date_endorsed_regional'] = $isEngp ? null : ($data['date_endorsed_regional'] ?? null);
        $data['release_events'] = $isEngp ? $record->releaseEvents->map(fn ($event) => ['id' => $event->id, 'period_component' => $event->period_component, 'component_label' => $event->component_label, 'date_report_released_cenro' => $event->date_report_released_cenro?->toDateString()])->values()->all() : [];
        $data['routing_corrections_count'] = $correctionCounts[$sourceKey.':'.$record->getKey()] ?? 0;
        $data['stage'] = $this->stage($record);
        $data['routing_complete'] = $this->isRoutingComplete($record);
        $data['completed_at'] = $data['routing_complete'] ? $this->routingCompletedAt($record) : null;
        $data['can_transition'] = $this->organization->canUseSubmissionTrackingSource(auth()->user(), $sourceKey, $source['ability']);
        $pambRouting = $sourceKey === 'conservation' ? $this->pambRouting->present($record) : ['applicable' => false, 'timeline' => [], 'current_document_location' => null, 'routing_summary' => [], 'summary_metrics' => []];
        $data['pamb_routing_applicable'] = $pambRouting['applicable'];
        $data['routing_timeline'] = $pambRouting['timeline'];
        if ($pambRouting['applicable'] && ($user = auth()->user())) {
            $data['pamb_action_flags'] = [
                'can_submit' => $this->pambAccess->canPerformForSubmission($user, 'submit', $record),
                'can_review' => $this->pambAccess->canPerformForSubmission($user, 'review', $record),
                'can_release' => $this->pambAccess->canPerformForSubmission($user, 'release', $record),
                'can_return_for_penro_correction' => $this->pambAccess->canRecordInternalRouting($user, $record, PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION),
                'can_approve_for_regional_release' => $this->pambAccess->canRecordInternalRouting($user, $record, PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL),
            ];
            $data['routing_timeline'] = array_map(function (array $stage) use ($user, $record): array {
                $stage['stage_key'] = $stage['stage_key'] ?? $stage['key'];
                $stage['can_record'] = $stage['can_record'] && $this->pambAccess->canRecordInternalRouting($user, $record, $stage['stage_key']);
                return $stage;
            }, $data['routing_timeline']);
            $canonicalTransitionAllowed = $this->pambAccess->canPerformCanonical($user, $record, $data['stage']);
            $internalTransitionAllowed = collect($data['routing_timeline'])
                ->contains(fn (array $stage): bool => ($stage['status'] ?? null) === 'current' && (bool) ($stage['can_record'] ?? false));
            $data['can_transition'] = $data['can_transition'] && ($canonicalTransitionAllowed || $internalTransitionAllowed);
        }
        if ($pambRouting['applicable']) {
            $data['routing_timeline'] = array_map(function (array $stage) use ($record, $sourceKey): array {
                $stage['stage_key'] = $stage['stage_key'] ?? $stage['key'];
                $stage['attachment_allowed'] = $this->canAttachRoutingCopy($sourceKey, $record, (string) $stage['stage_key']);
                return $stage;
            }, $data['routing_timeline']);
        }
        $data['current_document_location'] = $pambRouting['current_document_location'];
        $data['routing_summary'] = $pambRouting['routing_summary'];
        $data['routing_summary_metrics'] = $pambRouting['summary_metrics'];
        $data['mov_processing'] = $sourceKey === 'conservation' && $pambRouting['applicable'] ? $this->pambMov->present($record) : ['applicable' => false];
        $data['mov_progress_display'] = $data['mov_processing']['applicable']
            ? trim(($data['mov_processing']['percent'] ?? '').'% '.($data['mov_processing']['status_label'] ?? ''))
            : 'Not Applicable';
        $data['turnaround_display'] = $this->semanticTurnaroundDisplay($record, $data, $data['mov_processing']);
        $data['routing'] = $sourceKey === 'conservation' && $pambRouting['applicable']
            ? app(DocumentRoutingPresenter::class)->presentPamb($record, $pambRouting)
            : app(DocumentRoutingPresenter::class)->present($record, $sourceKey, $routingAudits, $routingEvents);
        $latestPambOccurredAt = $pambRouting['applicable']
            ? collect($pambRouting['timeline'] ?? [])
                ->filter(fn (mixed $stage): bool => is_array($stage) && filled($stage['occurred_at'] ?? null))
                ->max(fn (array $stage): string => (string) ($stage['occurred_at'] ?? ''))
            : null;
        if ($sourceKey === 'conservation' && $pambRouting['applicable'] && $routingEvents->isNotEmpty()) {
            $latest = $routingEvents->sortBy('id')->last();
            $genericIsAuthoritative = $latestPambOccurredAt === null
                || $latest->occurred_at === null
                || $latest->occurred_at->toIso8601String() >= (string) $latestPambOccurredAt;
            if ($genericIsAuthoritative
                && $latest instanceof \App\Models\DocumentRoutingEvent
                && in_array($latest->event_key, ['returned_for_correction', 'correction_received', 'forwarded'], true)) {
                $targetStage = (string) $latest->to_stage;
                $targetCategory = $this->organization->normalizeCategory($targetStage);
                $targetOffice = $latest->to_office;
                if ($latest->event_key === 'returned_for_correction' && $targetCategory === OrganizationalAccessService::CENRO_RECORDS && (blank($targetOffice) || strcasecmp((string) $targetOffice, 'previous accountable sender') === 0)) {
                    $targetOffice = $record->getAttribute('target_office');
                }
                $data['routing']['current_stage'] = $targetStage;
                $data['routing']['current_location'] = $targetOffice ?: $data['routing']['current_location'];
                $data['routing']['current_status'] = in_array($latest->event_key, ['returned_for_correction', 'correction_received'], true) ? 'Needs Correction' : $data['routing']['current_status'];
                $data['routing']['next_expected_action'] = match ($latest->event_key) {
                    'returned_for_correction' => 'Receive Correction',
                    'correction_received' => 'Resubmit Corrected Copy',
                    default => 'Record PENRO Receipt',
                };
                $data['routing']['responsible_user_category'] = $targetCategory ?: $data['routing']['responsible_user_category'];
                $data['routing']['responsible_office'] = $targetOffice ?: $data['routing']['responsible_office'];
                $data['routing']['correction'] = in_array($latest->event_key, ['returned_for_correction', 'correction_received'], true);
                $correctionEvent = $latest->event_key === 'returned_for_correction'
                    ? $latest
                    : $routingEvents->reverse()->first(fn (mixed $event): bool => $event instanceof \App\Models\DocumentRoutingEvent && $event->event_key === 'returned_for_correction');
                $data['routing']['correction_reason_key'] = data_get($correctionEvent?->metadata, 'correction_reason_key');
                $data['routing']['correction_reason'] = data_get($correctionEvent?->metadata, 'correction_reason');
                $data['routing']['correction_detail'] = data_get($correctionEvent?->metadata, 'correction_detail');
                $data['routing']['last_action'] = [
                    'label' => $latest->event_key === 'returned_for_correction' ? 'Returned for Correction' : ($latest->event_key === 'correction_received' ? 'Correction Received' : 'Forwarded to PENRO Records'),
                    'occurred_at' => $latest->occurred_at?->toIso8601String(),
                    'recorded_by' => $latest->recordedBy?->name,
                    'remarks' => $latest->remarks,
                    'attachment' => $this->routingAttachments->forDocumentEvents([$latest->id])[$latest->id] ?? null,
                ];
                if ($data['routing']['last_action']['attachment'] instanceof \App\Models\SubmissionRoutingAttachment) {
                    $data['routing']['last_action']['attachment'] = $this->routingAttachments->descriptor($data['routing']['last_action']['attachment']);
                }
                $data['routing']['last_action_actor_category'] = $latest->recordedBy ? $this->organization->effectiveCategory($latest->recordedBy) : null;
                $data['routing']['last_action_actor_office'] = $latest->recordedBy ? $this->organization->normalizeOffice($latest->recordedBy->office_designated) : null;
                if (in_array($latest->event_key, ['returned_for_correction', 'correction_received'], true)) {
                    $data['routing_summary']['current_status'] = $latest->event_key === 'returned_for_correction' ? 'Needs Correction' : 'Correction In Progress';
                    $data['routing_summary']['status_context'] = null;
                    $data['routing_summary']['current_location'] = $targetOffice ?: $data['routing_summary']['current_location'];
                    $data['routing_summary']['next_expected_action'] = $latest->event_key === 'returned_for_correction' ? 'Receive Correction' : 'Resubmit Corrected Copy';
                    $data['routing_summary']['last_action'] = $data['routing']['last_action'];
                    $data['routing_timeline'] = array_map(function (array $stage) use ($latest): array {
                        if (($stage['status'] ?? null) !== 'current') return $stage;
                        return [
                            ...$stage,
                            'stage_key' => 'correction_assignment',
                            'key' => 'correction_assignment',
                            'label' => $latest->event_key === 'returned_for_correction' ? 'Returned for Correction' : 'Correction In Progress',
                            'action_label' => null,
                            'held_at' => $latest->to_office,
                        ];
                    }, $data['routing_timeline']);
                }
                if ($latest->event_key === 'returned_for_correction') {
                    $data['routing']['actions'] = [[
                        'key' => 'receive_correction',
                        'label' => 'Correction received',
                        'action_label' => 'Receive Correction',
                        'to' => $targetStage,
                        'to_office' => $targetOffice,
                        'correction' => false,
                        'correction_cycle' => true,
                        'attachment_allowed' => false,
                    ]];
                } elseif ($latest->event_key === 'correction_received') {
                    $data['routing']['actions'] = [[
                        'key' => 'forward_to_penro_records',
                        'label' => 'Corrected copy resubmitted to PENRO Records',
                        'action_label' => 'Resubmit Corrected Copy',
                        'to' => $targetStage,
                        'to_office' => $targetOffice,
                        'correction' => false,
                        'correction_cycle' => true,
                        'attachment_allowed' => true,
                    ]];
                } else {
                    $data['routing']['actions'] = [[
                        'key' => 'receive_at_penro_records',
                        'label' => 'Received by PENRO Records Unit',
                        'action_label' => 'Receive',
                        'to' => $targetStage,
                        'to_office' => $targetOffice,
                        'correction' => false,
                        'attachment_allowed' => true,
                    ], [
                        'key' => 'return_for_correction_penro_records',
                        'label' => 'Returned by PENRO Records for Correction',
                        'action_label' => 'Return for Correction',
                        'to' => $targetStage,
                        'to_office' => $targetOffice,
                        'correction' => true,
                        'correction_reference_allowed' => true,
                        'receipt_correction_context' => 'penro_records',
                        'attachment_allowed' => false,
                    ]];
                }
                if ($actor = auth()->user()) {
                    $genericPresentation = $this->genericRouting->presentation($record, $sourceKey, $routingEvents, $actor);
                    $data['can_transition'] = $this->organization->canUseSubmissionTrackingSource($actor, $sourceKey, $source['ability'])
                        && collect($genericPresentation['allowed_actions'] ?? [])->isNotEmpty();
                }
            }
        }
        if ($sourceKey === 'conservation' && $pambRouting['applicable']) {
            // A correction recipient is never allowed to initiate a second
            // correction cycle before acknowledging the returned assignment.
            if ((bool) data_get($data, 'routing.correction', false)
                && $this->organization->normalizeCategory(data_get($data, 'routing.responsible_user_category')) === OrganizationalAccessService::CENRO_RECORDS
                && collect(data_get($data, 'routing.actions', []))->pluck('key')->contains('return_for_correction_penro_records')) {
                $data['routing']['actions'] = [[
                    'key' => 'receive_correction',
                    'label' => 'Correction received',
                    'action_label' => 'Receive Correction',
                    'correction' => false,
                    'correction_cycle' => true,
                    'attachment_allowed' => false,
                ]];
                $data['routing']['next_expected_action'] = 'Receive Correction';
            }
            $actor = auth()->user();
            $actorOwnsCurrentStage = $actor
                && ! $this->organization->isGlobal($actor)
                && $this->isCurrentOperationalOwner($data, $actor);
            if (! $actorOwnsCurrentStage || ! $data['can_transition']) {
                $data['routing']['actions'] = [];
            }
        }
        // Executable actions are actor-scoped for every source.  Outgoing and
        // observer rows remain viewable, but never expose mutation controls.
        if (! auth()->user() || $this->organization->isGlobal(auth()->user()) || ! $this->isCurrentOperationalOwner($data, auth()->user()) || ! $data['can_transition']) {
            $data['routing']['actions'] = [];
        }
        $data['routing']['attachment_allowed'] = $this->canAttachRoutingCopy($sourceKey, $record, (string) ($data['routing']['current_stage'] ?? $data['stage']));
        if ($this->usesGenericRecord($sourceKey, $record)) {
            $data['current_document_location'] = $data['routing']['current_location'];
            $data['stage'] = $data['routing']['current_stage'] ?? $data['stage'];
            $data['routing_complete'] = $sourceKey === 'engp' ? $this->isRoutingComplete($record) : ($data['stage'] ?? null) === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL;
            $data['completed_at'] = $data['routing_complete'] ? data_get($data['routing'], 'last_action.occurred_at') : null;
        }
        $data['current_document'] = $this->routingAttachments->currentDescriptor(
            $sourceKey,
            (int) $record->getKey(),
            $data['mov_attachment'],
            $data['mov_url'],
            data_get($data, 'mov_attachment.name', 'Original MOV / report'),
        );
        return $data;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $movProcessing */
    private function semanticTurnaroundDisplay(Model $record, array $data, array $movProcessing): string
    {
        if (($movProcessing['applicable'] ?? false) === true) {
            $label = trim((string) data_get($movProcessing, 'turnaround.label', ''));
            return $label === 'Not started' ? 'Not Started' : ($label !== '' ? $label : 'Not Started');
        }

        $days = $data['days_complied'] ?? null;
        if (is_int($days) || (is_string($days) && ctype_digit(trim($days)))) {
            $normalizedDays = (int) $days;
            $unit = $normalizedDays === 1 ? 'working day' : 'working days';
            return $data['date_received_penro']
                ? 'Completed in '.$normalizedDays.' '.$unit
                : $normalizedDays.' '.$unit;
        }

        if (is_string($days) && trim($days) !== '' && $days !== 'No Data') {
            return trim($days);
        }

        $started = filled($data['date_accomplished'] ?? null) || filled($data['date_conducted'] ?? null);
        return $started && filled($data['submission_status'] ?? null)
            ? (string) $data['submission_status']
            : 'Not Started';
    }
    /** @return array<string, Collection<int,AuditLog>> */
    private function routingAudits(Collection $loaded): array
    {
        $items = $loaded;
        if ($items->isEmpty()) return [];

        $logs = AuditLog::query()
            ->where('event_type', 'submission_tracking')
            ->where(function ($query) use ($items): void {
                foreach ($items->groupBy('key') as $source => $sourceItems) {
                    $query->orWhere(fn ($inner) => $inner
                        ->where('entity_type', $source)
                        ->whereIn('entity_id', $sourceItems->pluck('record')->map(fn (Model $record): string => (string) $record->getKey())->all()));
                }
            })
            ->with('user:id,name,section')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $logs->groupBy(fn (AuditLog $log): string => $log->entity_type.':'.$log->entity_id)->all();
    }

    /** @return array<string, Collection<int,DocumentRoutingEvent>> */
    private function genericRoutingEvents(Collection $loaded): array
    {
        $items = $loaded;
        if ($items->isEmpty()) return [];

        $events = DocumentRoutingEvent::query()
            ->where(function ($query) use ($items): void {
                foreach ($items->groupBy('key') as $source => $sourceItems) {
                    $query->orWhere(fn ($inner) => $inner
                        ->where('source_type', $source)
                        ->whereIn('source_id', $sourceItems->pluck('record')->map(fn (Model $record): string => (string) $record->getKey())->all()));
                }
            })
            ->with('recordedBy:id,name,section,office_designated')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return $events->groupBy(fn (DocumentRoutingEvent $event): string => $event->source_type.':'.$event->source_id)->all();
    }

    private function usesGenericRecord(string $sourceKey, Model $record): bool
    {
        if ($sourceKey === 'conservation') {
            return ! app(PambComplianceCalculator::class)->applies((string) $record->getAttribute('workflow_key'));
        }
        return true;
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
        if ($record instanceof EngpReportSubmission) {
            return $this->routingCompletedAt($record) !== null;
        }

        return $this->stage($record) === 'endorsed'
            && $this->routingCompletedAt($record) !== null;
    }

    /**
     * All originating report controllers use this guard before mutating the
     * source record. Completion remains defined by the canonical routing
     * status/completion policy above.
     */
    public function assertMutable(Model $record): void
    {
        if ($this->isRoutingComplete($record)) {
            throw ValidationException::withMessages(['submission' => 'Completed submissions are read-only.']);
        }
    }

    /**
     * A routing copy belongs to a custody handoff, never to a receipt-only
     * event or a completed record. This is the server-side contract shared by
     * the routing endpoints and presentation layer.
     */
    public function canAttachRoutingCopy(string $sourceKey, Model $record, string $stage): bool
    {
        if ($this->isRoutingComplete($record)) return false;

        if ($sourceKey !== 'engp' && $this->usesGenericRecord($sourceKey, $record) && $stage === 'receive_correction') return false;
        if ($sourceKey === 'conservation' && $record instanceof ConservationReportSubmission && ($this->pambRouting->isCorrectionStageKey($stage) || $this->pambRouting->isCorrectionActionKey($stage))) return false;
        if ($sourceKey !== 'engp' && $this->usesGenericRecord($sourceKey, $record) && $this->genericRouting->isCorrectionAction($record, $sourceKey, $stage)) return false;

        if ($sourceKey === 'conservation' && $record instanceof ConservationReportSubmission && $this->pambRouting->applies($record)) {
            $stage = $this->pambRouting->canonicalStageKey($stage);

            return ! in_array($stage, [
                PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL,
            ], true);
        }

        return $stage !== 'receive_at_penro_records_final';
    }

    private function routingCompletedAt(Model $record): ?string
    {
        $terminalStage = $this->terminalStage($record);
        $value = match ($terminalStage) {
            self::PENRO_RECEIPT => $record->getRawOriginal('date_received_penro'),
            self::REGIONAL_ENDORSEMENT => $record->getRawOriginal('date_endorsed_regional'),
            default => null,
        };

        return DatePresentationNormalizer::toDateString($value);
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
        return DatePresentationNormalizer::toDateString($record->getRawOriginal($field));
    }


    private function text(Model $record, string $field): ?string
    {
        $value = $record->getRawOriginal($field);

        return $value === null || $value === ''
            ? null
            : (is_scalar($value) ? (string) $value : DatePresentationNormalizer::toDateString($value));
    }
    private function safeExternalUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    private function hasProtectedAreaColumn(string $modelClass): bool
    {
        return in_array($modelClass, [
            \App\Models\BmsRecord::class,
            \App\Models\BmsReportSubmission::class,
            \App\Models\BamsReportSubmission::class,
            \App\Models\ImeaAssessment::class,
            \App\Models\ImeaReportSubmission::class,
            \App\Models\ImeaFacilityMaintenanceReport::class,
            \App\Models\Aws::class,
            \App\Models\IpafManagementReport::class,
            \App\Models\IpafRevenueCollection::class,
            \App\Models\ManagementPlan::class,
            \App\Models\TechnicalReport::class,
        ], true);
    }

    /** Apply request filters before records are hydrated and normalized. */
    private function applyDatabaseFilters($query, string $sourceKey, array $filters): void
    {
        $model = $query->getModel();
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();

        if (filled($filters['protected_area_id'] ?? null) && $this->hasProtectedAreaColumn($model::class)) {
            $query->where($table.'.protected_area_id', (int) $filters['protected_area_id']);
        }

        if (filled($filters['reporting_year'] ?? null)) {
            $year = (int) $filters['reporting_year'];
            if ($this->columnExists($schema, $table, 'reporting_year')) {
                $query->where($table.'.reporting_year', $year);
            } elseif ($this->columnExists($schema, $table, 'date_accomplished') || $this->columnExists($schema, $table, 'date_conducted')) {
                $query->where(function ($yearQuery) use ($table, $schema, $year): void {
                    if ($this->columnExists($schema, $table, 'date_accomplished')) $yearQuery->orWhereYear($table.'.date_accomplished', $year);
                    if ($this->columnExists($schema, $table, 'date_conducted')) $yearQuery->orWhereYear($table.'.date_conducted', $year);
                });
            }
        }

        if (filled($filters['reporting_period'] ?? null)) {
            $period = (string) $filters['reporting_period'];
            $periodColumn = $sourceKey === 'engp' ? 'period_label' : ($this->columnExists($schema, $table, 'reporting_period') ? 'reporting_period' : ($this->columnExists($schema, $table, 'semester') ? 'semester' : null));
            if ($periodColumn) $query->where($table.'.'.$periodColumn, $period);
        }

        if (filled($filters['module'] ?? null) && $this->columnExists($schema, $table, 'workflow_key')) {
            $workflowKeys = collect($query->clone()->reorder()->whereNotNull('workflow_key')->distinct()->orderBy('workflow_key')->pluck('workflow_key'))
                ->filter(fn ($workflowKey): bool => $this->sourceModuleLabel($sourceKey, $sourceKey === 'engp' ? EngpReportSubmission::class : $model::class, (string) $workflowKey) === (string) $filters['module'])
                ->values()->all();
            $query->whereIn($table.'.workflow_key', $workflowKeys);
        }

        if (filled($filters['status'] ?? null)) $this->applyStatusFilter($query, $sourceKey, (string) $filters['status'], $table, $schema);

        $officeColumn = $sourceKey === 'engp' ? 'office' : 'target_office';
        if (filled($filters['target_office'] ?? null) && $this->columnExists($schema, $table, $officeColumn)) {
            $query->where($table.'.'.$officeColumn, $filters['target_office']);
        }

        if (($filters['focus_source'] ?? null) === $sourceKey && filled($filters['focus_id'] ?? null)) {
            $query->where($table.'.id', (int) $filters['focus_id']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') return;

        $searchColumns = array_values(array_filter([
            'activity_name', 'document_type', 'target_office', 'office',
            'station_name', 'report_period_type', 'reporting_period', 'period_label', 'semester',
        ], fn (string $column): bool => $this->columnExists($schema, $table, $column)));
        if ($searchColumns === [] && ! $this->columnExists($schema, $table, 'protected_area_id')) return;

        $modelClass = $model::class;
        $query->where(function ($searchQuery) use ($query, $search, $searchColumns, $schema, $table, $sourceKey, $modelClass): void {
            foreach ($searchColumns as $column) {
                $searchQuery->orWhere($column, 'like', '%'.$search.'%');
            }
            if ($this->columnExists($schema, $table, 'workflow_key')) {
                $workflowKeys = collect($query->clone()->reorder()->whereNotNull('workflow_key')->distinct()->orderBy('workflow_key')->pluck('workflow_key'))
                    ->filter(fn ($workflowKey): bool => str_contains(strtolower($this->sourceModuleLabel($sourceKey, $modelClass, (string) $workflowKey)), strtolower($search)))
                    ->values()->all();
                if ($workflowKeys !== []) $searchQuery->orWhereIn($table.'.workflow_key', $workflowKeys);
            } else {
                $fixedModule = match ($sourceKey) {
                    'bms' => 'BMS Report',
                    'bams' => 'BAMS Report',
                    'imea' => 'IMEA Report',
                    'aws' => 'AWS Report',
                    'ipaf-management' => 'Management of IPAF',
                    'imea-maintenance' => 'IMEA Facility Maintenance',
                    'revenue' => 'Revenue Collection',
                    'management-plans' => 'Management Plans',
                    default => null,
                };
                if ($fixedModule && str_contains(strtolower($fixedModule), strtolower($search))) $searchQuery->orWhereRaw('1 = 1');
            }
            if ($this->columnExists($schema, $table, 'protected_area_id')) {
                $searchQuery->orWhereHas('protectedArea', fn ($areaQuery) => $areaQuery->where('name', 'like', '%'.$search.'%'));
            }
            if (Schema::hasTable('report_tracking_references')) {
                $searchQuery->orWhereExists(fn ($referenceQuery) => $referenceQuery
                    ->select(DB::raw('1'))
                    ->from('report_tracking_references')
                    ->whereColumn('report_tracking_references.source_id', $table.'.id')
                    ->where('report_tracking_references.source_type', $sourceKey)
                    ->where('report_tracking_references.tracking_number', $this->isTrackingNumber($search) ? '=' : 'like', $this->isTrackingNumber($search) ? strtoupper($search) : '%'.$search.'%'));
            }
        });
    }

    private function isTrackingNumber(string $value): bool
    {
        return preg_match('/^EDATS-(?:PA|ENGP)-\d{4}-\d+$/i', trim($value)) === 1;
    }

    /** Cache immutable schema metadata for the lifetime of this service instance. */
    private function columnExists($schema, string $table, string $column): bool
    {
        $key = $schema->getConnection()->getName().':'.$table;
        $columns = $this->tableColumns[$key] ??= $schema->getColumnListing($table);

        return in_array($column, $columns, true);
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
        return ! $search || str_contains(strtolower(implode(' ', [$record['tracking_number'] ?? '', $record['module'], $record['target_office'], $record['protected_area'], $record['activity_name'], $record['document_type'], $record['reporting_period']])), $search);
    }

    private function applyStatusFilter($query, string $sourceKey, string $status, string $table, $schema): void
    {
        if ($sourceKey === 'engp') {
            if ($status === RoutingStatusPresenter::COMPLETED) $query->whereNotNull($table.'.date_received_penro');
            elseif (in_array($status, [RoutingStatusPresenter::PENDING_CENRO, RoutingStatusPresenter::PENDING_PENRO], true)) {
                $query->whereNull($table.'.date_received_penro')->where(function ($stageQuery) use ($sourceKey, $status): void {
                    $workflows = app(EngpReportWorkflowRegistry::class)->all();
                    foreach ($workflows as $workflow) {
                        $key = (string) ($workflow['key'] ?? '');
                        if ($key === '') continue;
                        $needed = ($workflow['period'] ?? $workflow['reporting_frequency'] ?? '') === 'quarterly' ? 3 : 1;
                        $stageQuery->orWhere(function ($workflowQuery) use ($key, $needed, $status): void {
                            $workflowQuery->where('workflow_key', $key);
                            if ($status === RoutingStatusPresenter::PENDING_CENRO) $workflowQuery->whereHas('releaseEvents', null, '<', $needed);
                            else $workflowQuery->whereHas('releaseEvents', null, '>=', $needed);
                        });
                    }
                });
            }
            return;
        }

        match ($status) {
            RoutingStatusPresenter::COMPLETED => $query->whereNotNull($table.'.date_received_penro')->whereNotNull($table.'.date_endorsed_regional'),
            RoutingStatusPresenter::PENDING_REGIONAL => $query->whereNotNull($table.'.date_received_penro')->whereNull($table.'.date_endorsed_regional'),
            RoutingStatusPresenter::PENDING_PENRO => $query->whereNull($table.'.date_received_penro')->where(function ($stage) use ($table): void { $stage->whereNotNull($table.'.date_report_released_cenro')->orWhere(fn ($direct) => $this->routingPolicy->scopeDirectPenroQuery($direct)); }),
            RoutingStatusPresenter::PENDING_CENRO => $query->whereNull($table.'.date_received_penro')->whereNull($table.'.date_report_released_cenro')->where(fn ($notDirect) => $this->routingPolicy->scopeNotDirectPenroQuery($notDirect)),
            RoutingStatusPresenter::NO_ACTIVITY => $query->whereNull($table.'.date_received_penro')->whereNull($table.'.date_report_released_cenro')->whereNull($table.'.date_endorsed_regional'),
            default => null,
        };
    }
}
