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
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Modules\ModuleMetadataResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Support\DatePresentationNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogService;

final class SubmissionTrackingService
{
    public const CENRO_RELEASE = 'cenro_release';
    public const PENRO_RECEIPT = 'penro_receipt';
    public const REGIONAL_ENDORSEMENT = 'regional_endorsement';

    public function __construct(private readonly ConservationReportWorkflowRegistry $workflows, private readonly EngpReportWorkflowRegistry $engpWorkflows, private readonly ProtectedAreaRoutingPolicy $routingPolicy, private readonly PambRoutingTimelineService $pambRouting, private readonly PambMovProcessingService $pambMov, private readonly PambSubmissionAccessService $pambAccess, private readonly ProtectedAttachmentService $attachments, private readonly RoutingAttachmentService $routingAttachments, private readonly RoutingStatusPresenter $statusPresenter, private readonly AuditLogService $auditLogs, private readonly ModuleMetadataResolver $moduleResolver, private readonly OrganizationalAccessService $organization, private readonly DocumentRoutingTransitionService $genericRouting) {}

    /** @return Collection<int, array<string, mixed>> */
    public function records(array $filters = [], ?int $limitPerSource = null): Collection
    {
        $sources = $this->sources();
        if (($filters['program'] ?? null) === 'conservation') {
            unset($sources['engp']);
        } elseif (($filters['program'] ?? null) === 'engp') {
            $sources = array_intersect_key($sources, ['engp' => true]);
        }

        $loaded = collect($sources)
            ->flatMap(function (array $source, string $key) use ($filters, $limitPerSource) {
                $query = $source['model']::query();
                if ($key !== 'engp') $query->with('protectedArea:id,name,short_name');
                if ($key === 'conservation') $query->with(['routingEvents.recordedBy', 'movReviewEvents.recordedBy']);
                if ($key === 'engp') $query->with('releaseEvents');
                if ($key === 'conservation') {
                    $query->where(fn ($candidate) => $candidate
                        ->where(fn ($meeting) => $meeting
                            ->whereIn('workflow_key', PambComplianceCalculator::MEETING_WORKFLOWS)
                            ->whereNotNull('date_conducted'))
                        ->orWhere(fn ($other) => $other
                            ->whereNotNull('date_accomplished')
                            ->where(fn ($workflow) => $workflow
                                ->whereNotIn('workflow_key', PambComplianceCalculator::MEETING_WORKFLOWS)
                            ->orWhereNull('workflow_key'))));
                    if ($user = auth()->user()) {
                        $query = $this->pambAccess->scopeQuery($query, $user);
                    }
                } elseif ($user = auth()->user()) {
                    if ($key === 'engp') {
                        $query = $this->organization->scopeDevelopmentQuery($query, $user);
                    } elseif ($this->hasProtectedAreaColumn($source['model'])) {
                        $query = $this->organization->scopeProtectedAreaQuery($query, $user);
                    }
                }
                $this->applyDatabaseFilters($query, $key, $filters);
                if ($key !== 'conservation' && $key !== 'engp' && ($source['requires_date_accomplished'] ?? true)) {
                    $query->whereNotNull('date_accomplished');
                }
                if ($limitPerSource !== null) {
                    $query->limit(max(1, $limitPerSource));
                }
                return $query->get()->map(fn (Model $record) => ['record' => $record, 'key' => $key, 'source' => $source]);
            });

        $this->moduleResolver->prime($loaded->pluck('record'));
        $correctionCounts = $this->correctionCounts($loaded);

        $routingAudits = $this->routingAudits($loaded);
        $routingEvents = $this->genericRoutingEvents($loaded);

        return $loaded
            ->map(fn (array $item): array => $this->normalize($item['record'], $item['key'], $item['source'], $correctionCounts, $routingAudits[$item['key'].':'.$item['record']->getKey()] ?? collect(), $routingEvents[$item['key'].':'.$item['record']->getKey()] ?? collect()))
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
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'has_more' => $candidates->count() > ($page * $perPage),
            ];
        }

        return [
            'records' => $records,
            'queues' => $this->queues($filters, $records),
            'modules' => $this->modules($records),
            ...($pagination === null ? [] : ['pagination' => $pagination]),
        ];
    }

    /** @return array<string, Collection<int,array<string,mixed>>> */
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

        if ($category === OrganizationalAccessService::PAMO && in_array(OrganizationalAccessService::CONSERVATION, $this->organization->effectiveUnits($user), true)) {
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

                // Legacy events may not have persisted actor office metadata.
                // CENRO history stays jurisdiction-scoped; PENRO categories
                // are province-wide under the existing organizational policy.
                return ! in_array($category, $cenroCategories, true)
                    || $this->organization->normalizeOffice($record['target_office'] ?? null) === $office;
            });
        })->sortByDesc(function (array $record): string {
            return data_get($record, 'routing.last_updated')
                ?? data_get($record, 'routing_summary.last_updated')
                ?? '';
        })->values();
    }

    private function genericQueue(Collection $records, string $queue): Collection
    {
        $keys = match ($queue) {
            'pamo_origin' => ['forward_from_pamo'],
            'cenro_focal' => ['forward_to_cenro_chief'],
            'cenro_correction' => ['forward_to_cenro_chief'],
            'cenro_chief' => ['receive_at_cenro_chief', 'forward_to_cenro_records'],
            'cenro_records' => ['receive_at_cenro_records', 'forward_to_penro_records'],
            'penro_receipt' => ['receive_at_penro_records'],
            'penro_records_routing' => ['forward_to_office_penro'],
            'office_initial_routing' => ['receive_at_office_penro', 'assign_to_tsd_chief'],
            'tsd_routing' => ['receive_at_tsd_chief', 'forward_to_cds_focal'],
            'cds_processing' => ['receive_at_cds_focal', 'forward_to_cds_chief'],
            'cds_correction' => ['forward_to_cds_chief'],
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

        $incoming = collect($queues)
            ->except(['history', 'release_history', 'processed', 'active', 'in_transit'])
            ->flatten(1)
            ->filter(fn (mixed $row): bool => is_array($row) && ! ($row['routing_complete'] ?? false))
            ->unique($key)
            ->values();

        $outgoing = collect($queues)
            ->only(['processed'])
            ->flatten(1)
            ->filter(fn (mixed $row): bool => is_array($row) && ! ($row['routing_complete'] ?? false))
            ->unique($key)
            ->reject(fn (array $row): bool => $incoming->contains(fn (array $active): bool => $key($active) === $key($row)))
            ->sortByDesc(fn (array $row): string => data_get($row, 'routing.last_updated')
                ?? data_get($row, 'routing_summary.last_updated')
                ?? '')
            ->values();

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

        return ['incoming' => $incoming, 'outgoing' => $outgoing, 'history' => $history];
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

    public function transition(string $sourceKey, int $id, string $stage, ?string $date, ?int $userId, ?string $remarks = null): DocumentRoutingEvent|PambRoutingEvent|null
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
            return $this->genericRouting->transition($record, $sourceKey, $stage, $userId, $remarks);
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
        if ($userId && $record->getConnection()->getSchemaBuilder()->hasColumn($record->getTable(), 'updated_by')) {
            $changes['updated_by'] = $userId;
        }
        $canonicalEvent = DB::transaction(function () use ($record, $changes, $stage, $value, $userId): ?PambRoutingEvent {
            $record->update($changes);
            return $record instanceof ConservationReportSubmission && $this->pambRouting->applies($record)
                ? $this->pambRouting->recordCanonical($record, $stage, $value, $userId)
                : null;
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
            $locked->update([$field => $value, ...($locked->getConnection()->getSchemaBuilder()->hasColumn($locked->getTable(), 'updated_by') ? ['updated_by' => $userId] : [])]);
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
    private function normalize(Model $record, string $sourceKey, array $source, array $correctionCounts = [], Collection $routingAudits = new Collection, Collection $routingEvents = new Collection): array
    {
        $isEngp = $sourceKey === 'engp';
        $period = $isEngp
            ? $record->getAttribute('period_label')
            : ($record->getAttribute('reporting_period') ?: $record->getAttribute('semester'));
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
            'date_conducted' => $this->text($record, 'date_conducted'),
            'date_accomplished' => null,
            'reporting_period' => $period,
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
            $data['can_transition'] = $data['can_transition'] && $this->pambAccess->canPerformCanonical($user, $record, $data['stage']);
            $data['routing_timeline'] = array_map(function (array $stage) use ($user, $record): array {
                $stage['stage_key'] = $stage['stage_key'] ?? $stage['key'];
                $stage['can_record'] = $stage['can_record'] && $this->pambAccess->canRecordInternalRouting($user, $record, $stage['stage_key']);
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
        if ($this->usesGenericRecord($sourceKey, $record)) {
            $data['current_document_location'] = $data['routing']['current_location'];
            $data['stage'] = $data['routing']['current_stage'] ?? $data['stage'];
            $data['routing_complete'] = ($data['stage'] ?? null) === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::RELEASED_REGIONAL;
            $data['completed_at'] = $data['routing_complete'] ? data_get($data['routing'], 'last_action.occurred_at') : null;
        }
        $latestRoutingCopy = $this->routingAttachments->latest($sourceKey, (int) $record->getKey());
        $data['current_document'] = $latestRoutingCopy
            ? [...$this->routingAttachments->descriptor($latestRoutingCopy), 'source' => 'Routing attachment']
            : ($data['mov_url'] ? ['name' => data_get($data, 'mov_attachment.name', 'Original MOV / report'), 'download_url' => $data['mov_url'], 'preview_url' => $data['mov_url'], 'source' => 'Original MOV / report'] : null);
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
        $items = $loaded->filter(fn (array $item): bool => $this->usesGenericRecord($item['key'], $item['record']));
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
        return $this->stage($record) === 'endorsed'
            && $this->routingCompletedAt($record) !== null;
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

        $officeColumn = $sourceKey === 'engp' ? 'office' : 'target_office';
        if (filled($filters['target_office'] ?? null) && $schema->hasColumn($table, $officeColumn)) {
            $query->where($table.'.'.$officeColumn, $filters['target_office']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') return;

        $searchColumns = array_values(array_filter([
            'activity_name', 'document_type', 'target_office', 'office',
            'station_name', 'station', 'report_type', 'period_label',
        ], fn (string $column): bool => $schema->hasColumn($table, $column)));
        if ($searchColumns === [] && ! $schema->hasColumn($table, 'protected_area_id')) return;

        $query->where(function ($searchQuery) use ($search, $searchColumns, $schema, $table): void {
            foreach ($searchColumns as $column) {
                $searchQuery->orWhere($column, 'like', '%'.$search.'%');
            }
            if ($schema->hasColumn($table, 'protected_area_id')) {
                $searchQuery->orWhereHas('protectedArea', fn ($areaQuery) => $areaQuery->where('name', 'like', '%'.$search.'%'));
            }
        });
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
