<?php

namespace App\Services;

use App\Models\DocumentRoutingEvent;
use App\Models\ConservationReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\OfficeTatConfigurationVersion;
use App\Models\OrganizationalOffice;
use App\Models\PamoPambDraft;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\HistoricalReports\HistoricalDateGapProjector;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Evidence-first CDS office metrics. It reads source rows and accepted events directly. */
final class OfficeTatAnalyticsService
{
    /** @var array<int,string> */
    private array $pamoOfficeCache = [];
    private readonly int $documentBatchSize;
    private readonly int $eventChunkSize;

    public const DRAFT_WEIGHTS = ['timeliness' => 0.50, 'tat_compliance' => 0.35, 'volume' => 0.15];

    private const HANDOFF_EVENTS = ['forwarded', 'endorsed', 'released', 'approved', 'recommended', 'returned_for_correction'];

    public function __construct(
        private readonly OrganizationalAccessService $organization,
        private readonly ConservationReportWorkflowRegistry $conservation,
        private readonly EngpReportWorkflowRegistry $engp,
        private readonly BusinessCalendarService $calendar,
        private readonly HistoricalDateGapProjector $historicalDateGaps,
        private readonly SubmissionTrackingService $tracking,
        int $documentBatchSize = 300,
        int $eventChunkSize = 500,
    ) {
        $this->documentBatchSize = min(500, max(1, $documentBatchSize));
        $this->eventChunkSize = min(1000, max(1, $eventChunkSize));
    }

    /** @return array<string,mixed> */
    public function analytics(User $user, array $filters = []): array
    {
        $category = strtoupper((string) ($filters['category'] ?? 'PAMO'));
        abort_unless(in_array($category, ['PAMO', 'CENRO'], true), 422);
        $this->assertCategoryAccess($user, $category);

        $periodKey = $this->canonicalPeriodKey($filters['processing_period'] ?? null);
        [$periodStart, $periodEndExclusive] = $this->periodBounds($periodKey);
        $asOf = $this->parseAsOf($filters['as_of_date'] ?? null);
        $asOfExclusive = $asOf->addDay()->startOfDay();
        $cohortEndExclusive = $periodEndExclusive->lessThan($asOfExclusive) ? $periodEndExclusive : $asOfExclusive;
        $workflows = $this->workflowOptions($user);
        $offices = $this->officesFor($category, $user);
        $rows = collect($offices)->mapWithKeys(fn (string $office): array => [$office => $this->emptyRow($category, $office)])->all();
        $sourceEvidenceRows = 0;
        $this->primePamoOfficeCache();
        $evidenceType = $filters['evidence_type'] ?? 'all';
        $liveOnly = $evidenceType === 'live';
        $includeLive = in_array($evidenceType, ['live', 'all'], true);

        if ($includeLive && $cohortEndExclusive->greaterThan($periodStart)) {
            $sourceEvidenceRows = $this->aggregateLiveEvidence($user, $category, $periodStart, $cohortEndExclusive, $asOf, $filters, $rows);
        }

        $this->addHistoricalEvidence($user, $category, $periodStart, $cohortEndExclusive, $filters, $rows);

        $targets = $this->configurationFor($category, array_keys($rows), $periodKey);
        foreach ($rows as $office => &$row) {
            $configuration = $targets[$office] ?? null;
            $weights = $configuration?->score_weights ?: self::DRAFT_WEIGHTS;
            $target = $configuration?->volume_target;
            $row['volume_target'] = $target;
            $row['volume_percent'] = $target && $target > 0
                ? min(100, 100 * $row['documents_received'] / $target)
                : null;
            $row['timeliness_percent'] = $row['timeliness_eligible'] > 0
                ? 100 * $row['timeliness_on_time'] / $row['timeliness_eligible']
                : null;
            $row['tat_compliance_percent'] = null;
            $row['tat_compliance_eligible'] = 0;
            $row['tat_compliance_missing_limit'] = $row['documents_received'];
            $row['tat_average_elapsed_days'] = $row['tat_completed_documents'] > 0
                ? round($row['tat_elapsed_days_total'] / $row['tat_completed_documents'], 2)
                : null;
            $row['tat_average_elapsed_seconds'] = $row['tat_completed_documents'] > 0
                ? $row['tat_elapsed_seconds_total'] / $row['tat_completed_documents']
                : null;
            $row['event_derived_tat_completed_documents'] = $row['tat_completed_documents'];
            if (! $liveOnly) {
                $row['manual_historical_tat_average_elapsed_days'] = $row['manual_historical_tat_completed_documents'] > 0
                    ? round($row['manual_historical_tat_elapsed_days_total'] / $row['manual_historical_tat_completed_documents'], 2)
                    : null;
                $row['manual_historical_tat_average_elapsed_seconds'] = $row['manual_historical_tat_completed_documents'] > 0
                    ? $row['manual_historical_tat_elapsed_seconds_total'] / $row['manual_historical_tat_completed_documents']
                    : null;
                foreach ([HistoricalDateGapProjector::PREPARATION, HistoricalDateGapProjector::PROCESSING, HistoricalDateGapProjector::HANDOFF] as $basis) {
                    $samples = $row["manual_historical_{$basis}_completed_documents"];
                    $row["manual_historical_{$basis}_average_elapsed_calendar_days"] = $samples > 0
                        ? (float) ($row["manual_historical_{$basis}_elapsed_calendar_days_total"] / $samples)
                        : null;
                }
            }
            $available = $row['timeliness_percent'] !== null
                && $row['tat_compliance_percent'] !== null
                && $row['volume_percent'] !== null;
            $row['score'] = $available
                ? round(($weights['timeliness'] * $row['timeliness_percent']) + ($weights['tat_compliance'] * $row['tat_compliance_percent']) + ($weights['volume'] * $row['volume_percent']), 2)
                : null;
            $row['score_status'] = $available ? 'Scored' : 'Not assessed: required component evidence or target is missing.';
            $row['weights'] = $weights;
            $row['configuration_version'] = $configuration?->version;
            $row['configuration_saved_at'] = $configuration?->created_at?->toIso8601String();
            $row['period_key'] = $periodKey;
            $row['as_of_date'] = $asOf->toDateString();
        }
        unset($row);
        $this->pamoOfficeCache = [];

        $orderedRows = collect($rows)->sortByDesc(fn (array $row): int => (int) ($row['score'] !== null))->sortByDesc(fn (array $row): float => (float) ($row['score'] ?? -1))->values()->all();

        $result = [
            'category' => $category,
            'processing_period' => $periodKey,
            'processing_period_label' => $this->periodLabel($periodKey),
            'processing_period_start' => $periodStart->toDateString(),
            'processing_period_end' => $periodEndExclusive->subDay()->toDateString(),
            'assessment_period' => $this->assessmentPeriod($periodKey),
            'as_of_date' => $asOf->toDateString(),
            'reporting_period' => $filters['reporting_period'] ?? null,
            'reporting_year' => $filters['reporting_year'] ?? null,
            'evidence_type' => $filters['evidence_type'] ?? 'all',
            'rows' => $orderedRows,
            'pamo_preparation' => $category === 'PAMO'
                ? $this->pamoPreparationAnalytics($user, $periodKey, $periodStart, $cohortEndExclusive, $asOf, $filters)
                : null,
            'options' => [
                'offices' => array_values(array_keys($rows)),
                'workflows' => $workflows,
                'protectedAreas' => $this->authorizedProtectedAreas($user),
                'assessment_types' => [
                    ['value' => 'semestral', 'label' => 'Semestral'],
                    ['value' => 'annual', 'label' => 'Annual'],
                ],
                'assessment_years' => range(CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->year, 2000, -1),
                'semesters' => [
                    ['value' => 'H1', 'label' => '1st semester · Jan–Jun'],
                    ['value' => 'H2', 'label' => '2nd semester · Jul–Dec'],
                ],
            ],
            'definitions' => [
                'timeliness' => 'On-time eligible reports ÷ eligible reports. The deadline and authoritative date come from the source module. Missing required source evidence is counted separately.',
                'tat' => 'Elapsed calendar time from this office’s accepted receipt to its next accepted handoff. Correction cycles are combined once per document; a later unfinished cycle remains pending.',
                'tat_compliance' => 'Not applicable because the current codebase defines no office-specific processing limit for the selected work.',
                'documents_received' => 'Distinct source record and receiving office with an accepted first receipt in the selected processing-period cohort.',
                'volume' => 'Received documents ÷ configured positive office target, capped at 100%. Without a target, volume is not applicable.',
                'historical' => 'Manual date-only evidence is projected into separate calendar-day gaps: conducted-to-accomplished, source-authoritative accomplished-to-original CENRO release, and original CENRO release-to-PENRO receipt. Unsupported, missing, invalid, and direct-to-PENRO endpoints are not averaged; no gap is a measured office custody interval.',
            ],
            'source_evidence_rows' => $sourceEvidenceRows,
        ];

        if ($liveOnly) {
            foreach ($result['rows'] as &$row) {
                foreach (array_keys($row) as $key) {
                    if ($key === 'historical_report_count' || str_starts_with($key, 'manual_historical_')) unset($row[$key]);
                }
            }
            unset($row);
            unset($result['definitions']['historical']);
        }

        return $result;
    }

    /** @return OfficeTatConfigurationVersion */
    public function saveConfiguration(User $user, array $data): OfficeTatConfigurationVersion
    {
        $category = strtoupper($data['office_category']);
        abort_unless(in_array($category, ['PAMO', 'CENRO'], true), 422);
        abort_unless($this->isCanonicalAssessmentPeriod($data['period_key'] ?? null), 422);
        $this->assertCategoryAccess($user, $category);
        abort_unless($user->can('office-tat.configure'), 403);
        $offices = $this->officesFor($category, $user);
        abort_unless(in_array($data['office'], $offices, true), 403);

        return DB::transaction(function () use ($user, $data, $category): OfficeTatConfigurationVersion {
            $query = OfficeTatConfigurationVersion::query()
                ->where('office_category', $category)
                ->where('period_key', $data['period_key']);
            $officeIdentityId = null;
            if ($category === 'PAMO' && Schema::hasColumn('office_tat_configuration_versions', 'organizational_office_id')) {
                $officeIdentityId = OrganizationalOffice::query()
                    ->where('office_type', 'pamo')->where('name', $data['office'])->value('id');
                if ($officeIdentityId !== null) {
                    $query->where(function ($sameIdentity) use ($officeIdentityId, $data): void {
                        $sameIdentity->where('organizational_office_id', $officeIdentityId)->orWhere('office', $data['office']);
                    });
                } else {
                    $query->where('office', $data['office']);
                }
            } else {
                $query->where('office', $data['office']);
            }
            $version = (int) $query->max('version') + 1;
            $attributes = [
                'office_category' => $category,
                'office' => $data['office'],
                'period_key' => $data['period_key'],
                'volume_target' => $data['volume_target'] ?? null,
                'score_weights' => [
                    'timeliness' => (float) $data['weights']['timeliness'],
                    'tat_compliance' => (float) $data['weights']['tat_compliance'],
                    'volume' => (float) $data['weights']['volume'],
                ],
                'version' => $version,
                'configured_by' => $user->getKey(),
            ];
            if ($category === 'PAMO' && Schema::hasColumn('office_tat_configuration_versions', 'organizational_office_id')) {
                $attributes['organizational_office_id'] = $officeIdentityId;
            }
            return OfficeTatConfigurationVersion::query()->create($attributes);
        });
    }

    /** Separate start-to-outbound preparation metric; never contributes to receipt TAT or score. */
    private function pamoPreparationAnalytics(User $user, string $periodKey, CarbonImmutable $periodStart, CarbonImmutable $cohortEndExclusive, CarbonImmutable $asOf, array $filters): array
    {
        $base = [
            'available' => Schema::hasTable('pamo_pamb_drafts') && Schema::hasTable('document_routing_events'),
            'basis' => 'First persisted PAMO draft started_at in the selected Asia/Manila assessment period.',
            'processing_period' => $periodKey,
            'cohort_start' => $periodStart->toDateString(),
            'cohort_end' => $cohortEndExclusive->greaterThan($periodStart) ? $cohortEndExclusive->subMicrosecond()->toDateString() : $periodStart->subDay()->toDateString(),
            'as_of_date' => $asOf->toDateString(),
            'rows' => [],
            'overall' => ['documents_started' => 0, 'completed_documents' => 0, 'pending_documents' => 0, 'missing_evidence' => 0, 'elapsed_seconds_total' => 0.0, 'average_elapsed_seconds' => null],
        ];
        if (! $base['available'] || ! $cohortEndExclusive->greaterThan($periodStart)) {
            return [...$base, 'available' => false, 'unavailable_reason' => $base['available'] ? 'No preparation starts fall within this assessment cutoff.' : 'PAMO draft and routing-event schema is not installed.'];
        }

        $organization = $this->organization;
        $officeIds = [];
        $officeNames = [];
        if ($organization->isGlobal($user)) {
            $officeFacts = OrganizationalOffice::query()->where('office_type', 'pamo')->orderBy('name')->get(['id', 'name']);
        } elseif ($this->isPenroOfficeTatReviewer($user)) {
            return [...$base, 'available' => false, 'unavailable_reason' => "PAMO preparation detail is outside this account's Office TAT review scope."];
        } else {
            $assignment = $organization->pamoAssignmentForProtectedArea((int) $user->protected_area_id);
            abort_unless($assignment && $organization->canViewPamoPilotWorkflow($user), 403);
            $officeFacts = OrganizationalOffice::query()->whereKey($assignment['pamo_office_id'])->get(['id', 'name']);
        }
        foreach ($officeFacts as $office) {
            $officeIds[] = (int) $office->id;
            $officeNames[(int) $office->id] = (string) $office->name;
        }

        $rows = [];
        foreach ($officeNames as $officeId => $officeName) {
            $rows[$officeId] = [
                'office_category' => 'PAMO', 'office_id' => $officeId, 'office' => $officeName,
                'documents_started' => 0, 'completed_documents' => 0, 'pending_documents' => 0,
                'missing_evidence' => 0, 'elapsed_seconds_total' => 0.0,
                'average_elapsed_seconds' => null, 'rank' => null,
            ];
        }

        $workflowFilter = trim((string) ($filters['workflow'] ?? 'all'));
        $nativeSources = app(\App\Services\Pamo\PamoNativeSourceRegistry::class);
        $nativeReady = $nativeSources->isSchemaReady();
        // Keep the cohort aligned with the active generic PA module catalog
        // accepted by PAMO submission authorization. Meeting workflows are
        // only a subset of that catalog (for example, Homestay is also a
        // supported PAMO workflow).
        $eligiblePreparationWorkflows = [...$this->conservation->pamoSubmissionKeys(), ...($nativeReady ? array_keys($nativeSources->all()) : [])];
        $workflowKey = null;
        $selectedSource = null;
        $validWorkflowFilter = $workflowFilter === '' || $workflowFilter === 'all';
        if (str_starts_with($workflowFilter, 'conservation:')) {
            $workflowKey = substr($workflowFilter, strlen('conservation:'));
            $selectedSource = 'conservation';
            $validWorkflowFilter = $this->conservation->supportsPamoSubmission($workflowKey);
        } elseif (str_contains($workflowFilter, ':')) {
            [$sourceCandidate, $sourceSelection] = explode(':', $workflowFilter, 2);
            $native = $nativeReady ? $nativeSources->findBySource($sourceCandidate) : null;
            if ($native && $sourceSelection === $sourceCandidate) {
                $workflowKey = $native['workflow'];
                $selectedSource = $sourceCandidate;
                $validWorkflowFilter = true;
            }
        }
        if (! $validWorkflowFilter) {
            return [...$base, 'available' => true, 'rows' => array_values($rows)];
        }

        $draftQuery = PamoPambDraft::query()
            ->whereIn('pamo_office_id', $officeIds)
            ->where('started_at', '>=', $periodStart)
            ->where('started_at', '<', $cohortEndExclusive)
            ->whereIn('workflow_key', $workflowKey ? [$workflowKey] : $eligiblePreparationWorkflows);
        if (! $organization->isGlobal($user)) $draftQuery->where('protected_area_id', (int) $user->protected_area_id);
        if (filled($filters['protected_area_id'] ?? null)) $draftQuery->where('protected_area_id', (int) $filters['protected_area_id']);
        $cutoff = $cohortEndExclusive;
        $draftQuery->with('pamoOffice:id,name')->orderBy('id')->chunkById($this->documentBatchSize, function (Collection $drafts) use (&$rows, $officeNames, $cutoff, $filters, $nativeSources, $nativeReady, $selectedSource): void {
            $documents = [];
            foreach ($drafts as $draft) {
                $payload = $draft->report_payload ?? [];
                if (filled($filters['reporting_period'] ?? null) && (string) ($payload['reporting_period'] ?? '') !== (string) $filters['reporting_period']) continue;
                $payloadYear = (string) ($payload['reporting_year'] ?? substr((string) ($payload['date_conducted'] ?? ''), 0, 4));
                if (filled($filters['reporting_year'] ?? null) && $payloadYear !== (string) $filters['reporting_year']) continue;
                $officeId = (int) $draft->pamo_office_id;
                $officeName = (string) ($draft->pamo_office_name ?: ($draft->pamoOffice?->name ?: ($officeNames[$officeId] ?? 'PAMO')));
                $native = $nativeReady ? $nativeSources->find((string) $draft->workflow_key) : null;
                $sourceKey = $native['source'] ?? 'conservation';
                if ($selectedSource !== null && $sourceKey !== $selectedSource) continue;
                $rows[$officeId] ??= ['office_category' => 'PAMO', 'office_id' => $officeId, 'office' => $officeName, 'documents_started' => 0, 'completed_documents' => 0, 'pending_documents' => 0, 'missing_evidence' => 0, 'elapsed_seconds_total' => 0.0, 'average_elapsed_seconds' => null, 'rank' => null];
                $rows[$officeId]['documents_started']++;
                $nativeSourceId = $native ? $draft->getAttribute($native['column']) : null;
                $sourceId = $nativeSourceId ?: $draft->conservation_report_submission_id;
                $documents[(int) $draft->id] = [
                    'office_id' => $officeId,
                    'started_at' => CarbonImmutable::parse($draft->started_at, BusinessCalendarService::TIMEZONE),
                    'status' => (string) $draft->status,
                    'source_key' => $sourceId ? $sourceKey : null,
                    'source_id' => $sourceId ? (int) $sourceId : null,
                    'forwarded_at' => $draft->forwarded_at ? CarbonImmutable::parse($draft->forwarded_at, BusinessCalendarService::TIMEZONE) : null,
                    'initial_seconds' => null,
                    'correction_seconds' => 0.0,
                    'correction_started_at' => null,
                    'correction_return_pending' => false,
                    'invalid' => false,
                ];
            }

            $sourceToDraft = [];
            foreach ($documents as $draftId => $document) {
                if ($document['source_id'] !== null) $sourceToDraft[$document['source_key'].':'.$document['source_id']] = $draftId;
            }
            if ($sourceToDraft !== [] && Schema::hasTable('document_routing_events')) {
                foreach (collect($documents)->pluck('source_key')->filter()->unique() as $eventSource) {
                    $sourceIds = collect($documents)->filter(fn (array $document): bool => $document['source_key'] === $eventSource && $document['source_id'] !== null)->pluck('source_id')->unique()->values();
                    if ($sourceIds->isEmpty()) continue;
                    $events = DB::table('document_routing_events')
                        ->select(['id', 'source_id', 'event_key', 'from_stage', 'to_stage', 'occurred_at', 'metadata'])
                        ->where('source_type', $eventSource)->whereIn('source_id', $sourceIds)
                        ->where('occurred_at', '<', $cutoff);
                    $this->forEachOrderedEventPage($events, 'source_id', function (object $event) use (&$documents, $sourceToDraft, $eventSource): void {
                        $draftId = $sourceToDraft[$eventSource.':'.(int) $event->source_id] ?? null;
                        if ($draftId === null) return;
                        $document = &$documents[$draftId];
                        $occurredAt = CarbonImmutable::parse($event->occurred_at, BusinessCalendarService::TIMEZONE);
                        if ($occurredAt->lessThan($document['started_at'])) return;
                        $metadata = is_array($event->metadata) ? $event->metadata : (json_decode((string) $event->metadata, true) ?: []);
                        $action = (string) ($metadata['action_key'] ?? '');
                        if ($event->event_key === 'correction_received' && $action === 'receive_correction' && $event->to_stage === DocumentRoutingProfileRegistry::PAMO_ORIGIN) {
                            if ($document['initial_seconds'] !== null && $document['correction_started_at'] === null) {
                                $document['correction_started_at'] = $occurredAt;
                                $document['correction_return_pending'] = false;
                            }
                        } elseif ($event->event_key === 'returned_for_correction' && $event->to_stage === DocumentRoutingProfileRegistry::PAMO_ORIGIN) {
                            $document['correction_return_pending'] = true;
                        } elseif ($event->event_key === 'forwarded'
                            && in_array($action, ['forward_from_pamo', 'forward_from_pamo_to_cenro_focal'], true)
                            && $event->from_stage === DocumentRoutingProfileRegistry::PAMO_ORIGIN) {
                            if ($document['initial_seconds'] === null) {
                                $seconds = $document['started_at']->diffInSeconds($occurredAt, false);
                                if ($seconds < 0) $document['invalid'] = true;
                                else $document['initial_seconds'] = (float) $seconds;
                            } elseif ($document['correction_started_at'] instanceof CarbonImmutable) {
                                $seconds = $document['correction_started_at']->diffInSeconds($occurredAt, false);
                                if ($seconds < 0) $document['invalid'] = true;
                                else $document['correction_seconds'] += (float) $seconds;
                                $document['correction_started_at'] = null;
                                $document['correction_return_pending'] = false;
                            }
                        }
                    });
                }
            }

            foreach ($documents as $document) {
                $officeId = $document['office_id'];
                if ($document['status'] === 'draft' && $document['source_id'] === null) {
                    $rows[$officeId]['pending_documents']++;
                    continue;
                }
                if ($document['initial_seconds'] === null) {
                    if ($document['forwarded_at'] === null || $document['forwarded_at']->greaterThanOrEqualTo($cutoff)) {
                        $rows[$officeId]['pending_documents']++;
                    } else {
                        $rows[$officeId]['missing_evidence']++;
                    }
                    continue;
                }
                if ($document['invalid']) {
                    $rows[$officeId]['missing_evidence']++;
                    continue;
                }
                if ($document['correction_return_pending'] || $document['correction_started_at'] instanceof CarbonImmutable) {
                    $rows[$officeId]['pending_documents']++;
                    continue;
                }
                $rows[$officeId]['completed_documents']++;
                $rows[$officeId]['elapsed_seconds_total'] += $document['initial_seconds'] + $document['correction_seconds'];
            }
        }, 'id');

        foreach ($rows as &$row) {
            if ($row['completed_documents'] > 0) $row['average_elapsed_seconds'] = $row['elapsed_seconds_total'] / $row['completed_documents'];
            foreach (['documents_started', 'completed_documents', 'pending_documents', 'missing_evidence'] as $field) $base['overall'][$field] += $row[$field];
            $base['overall']['elapsed_seconds_total'] += $row['elapsed_seconds_total'];
        }
        unset($row);

        $ranked = collect($rows)->sort(function (array $left, array $right): int {
            $leftAverage = $left['average_elapsed_seconds'];
            $rightAverage = $right['average_elapsed_seconds'];
            if ($leftAverage === null && $rightAverage !== null) return 1;
            if ($leftAverage !== null && $rightAverage === null) return -1;
            if ($leftAverage !== null && $rightAverage !== null && $leftAverage !== $rightAverage) return $leftAverage <=> $rightAverage;
            return strnatcasecmp($left['office'], $right['office']);
        })->values();
        $previousSeconds = null;
        $previousRank = null;
        foreach ($ranked as $index => $row) {
            if ($row['average_elapsed_seconds'] !== null) {
                $row['rank'] = $previousSeconds !== null && $row['average_elapsed_seconds'] === $previousSeconds ? $previousRank : $index + 1;
                $previousSeconds = $row['average_elapsed_seconds'];
                $previousRank = $row['rank'];
            }
            $ranked[$index] = $row;
        }
        $base['rows'] = $ranked->all();
        $base['overall']['average_elapsed_seconds'] = $base['overall']['completed_documents'] > 0
            ? $base['overall']['elapsed_seconds_total'] / $base['overall']['completed_documents']
            : null;

        return [...$base, 'available' => true];
    }

    private function aggregateLiveEvidence(User $user, string $category, CarbonImmutable $periodStart, CarbonImmutable $cohortEndExclusive, CarbonImmutable $asOf, array $filters, array &$rows): int
    {
        $sourceEvidenceRows = 0;
        foreach (app(\App\Services\OfficeTat\LiveReportSourceRegistry::class)->all() as $source) {
            if (! $this->canViewSource($user, $source)) continue;

            $this->receiptDocumentIdsQuery($source, $periodStart, $cohortEndExclusive, $filters)
                ->chunkById($this->documentBatchSize, function (Collection $documents) use ($user, $source, $category, $periodStart, $cohortEndExclusive, $asOf, $filters, &$rows, &$sourceEvidenceRows): void {
                    $ids = $documents->pluck('source_id')->map(fn ($id): int => (int) $id)->values();
                    if ($ids->isEmpty()) return;

                    $records = $this->loadEventRecordBatch($source, $ids, $user, $filters);
                    if ($records === []) return;

                    $cohortDocuments = $this->aggregateReceiptBatch($source, $ids, $records, $category, $periodStart, $cohortEndExclusive, $filters, $rows, $sourceEvidenceRows);
                    if ($cohortDocuments !== []) {
                        $this->addLiveTatIntervalBatch($source, $cohortDocuments, $records, $category, $periodStart, $asOf, $rows);
                    }
                }, 'source_id', 'source_id');
        }

        return $sourceEvidenceRows;
    }

    private function receiptDocumentIdsQuery(string $source, CarbonImmutable $from, CarbonImmutable $toExclusive, array $filters): QueryBuilder
    {
        $genericIds = DB::table('document_routing_events')
            ->select('source_id')
            ->where('source_type', $source)
            ->whereIn('event_key', ['received', 'correction_received'])
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $toExclusive);

        if ($source !== 'conservation') return $genericIds->distinct()->orderBy('source_id');

        $pambIds = $this->pambReceiptQuery($from, $toExclusive, $filters)
            ->select('conservation_report_submission_id as source_id')->toBase();
        $genericIds->union($pambIds);

        return DB::query()->fromSub($genericIds, 'receipt_documents')
            ->select('source_id')->distinct()->orderBy('source_id');
    }

    private function loadEventRecordBatch(string $source, Collection $ids, User $user, array $filters): array
    {
        $modelClass = $this->sourceModelClass($source);
        if (! $modelClass) return [];

        $model = new $modelClass;
        $table = $model->getTable();
        $schema = Schema::connection($model->getConnectionName());
        $query = $modelClass::query()->whereIn($table.'.id', $ids);
        if ($schema->hasColumn($table, 'is_historical')) {
            $query->where(fn (Builder $historical) => $historical->whereNull($table.'.is_historical')->orWhere($table.'.is_historical', false));
        }
        if (Schema::hasTable('historical_report_origins')) {
            $query->whereNotExists(function (QueryBuilder $origins) use ($source, $table): void {
                $origins->selectRaw('1')->from('historical_report_origins')
                    ->where('historical_report_origins.source', $source)
                    ->whereColumn('historical_report_origins.source_record_id', $table.'.id');
            });
        }
        if ($source !== 'engp') $query->with('protectedArea.supervisingOfficeAssignment.office');
        $query = $source === 'engp'
            ? $this->organization->scopeDevelopmentQuery($query, $user)
            : $this->organization->scopeProtectedAreaQuery($query, $user);

        return $query->get()
            ->filter(fn (Model $model): bool => $this->matchesReportFilter($model, $source, $filters))
            ->keyBy(fn (Model $model): int => (int) $model->getKey())
            ->all();
    }

    private function pambReceiptQuery(CarbonImmutable $from, CarbonImmutable $toExclusive, array $filters): Builder
    {
        $query = PambRoutingEvent::query()
            ->where('stage_key', 'like', PambRoutingTimelineService::RECORDS_RECEIVED.'%')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $toExclusive);
        $selector = trim((string) ($filters['workflow'] ?? ''));
        if ($selector === '' || $selector === 'all') return $query;

        [$selectedSource, $workflow] = array_pad(explode(':', $selector, 2), 2, '');
        if ($selectedSource !== 'conservation' || $workflow === '') return $query->whereNull('id');
        if ($workflow === 'conservation') {
            return $query->where(function (Builder $query): void {
                $query->whereNull('workflow_key')->orWhere('workflow_key', '')->orWhere('workflow_key', 'conservation');
            });
        }

        return $query->where('workflow_key', $workflow);
    }

    /** @param array<int,Model> $records @return array<string,array{source_id:int,office:string,first_receipt_event_key:string,first_receipt_at:string}> */
    private function aggregateReceiptBatch(string $source, Collection $ids, array $records, string $category, CarbonImmutable $from, CarbonImmutable $toExclusive, array $filters, array &$rows, int &$sourceEvidenceRows): array
    {
        $cohortDocuments = [];
        $countedOffices = [];
        $firstPenroReceipts = [];

        $consume = function (object $event) use ($source, $records, $category, &$rows, &$cohortDocuments, &$countedOffices, &$firstPenroReceipts, &$sourceEvidenceRows): void {
            $recordId = (int) $event->source_id;
            $record = $records[$recordId] ?? null;
            if (! $record) return;
            $sourceEvidenceRows++;

            $toCategory = $this->eventCategory($event->to_office);
            $receivingOffice = $this->eventOffice($record, $source, $toCategory, $event->to_office);
            if ($toCategory === $category && $receivingOffice && isset($rows[$receivingOffice])) {
                $key = $recordId.':'.$receivingOffice;
                if (! isset($countedOffices[$key])) {
                    $countedOffices[$key] = true;
                    $rows[$receivingOffice]['documents_received']++;
                    $rows[$receivingOffice]['event_derived_received']++;
                }
                $cohortKey = $source.':'.$recordId.':'.$receivingOffice;
                $occurredAt = (string) $event->occurred_at;
                if (! isset($cohortDocuments[$cohortKey]) || $occurredAt < $cohortDocuments[$cohortKey]['first_receipt_at']) {
                    $cohortDocuments[$cohortKey] = [
                        'source_id' => $recordId,
                        'office' => $receivingOffice,
                        'first_receipt_event_key' => (string) $event->event_key,
                        'first_receipt_at' => $occurredAt,
                    ];
                }
            }

            if ($toCategory === 'PENRO') {
                $occurredAt = CarbonImmutable::parse($event->occurred_at, BusinessCalendarService::TIMEZONE);
                if (! isset($firstPenroReceipts[$recordId]) || $occurredAt->lessThan($firstPenroReceipts[$recordId])) {
                    $firstPenroReceipts[$recordId] = $occurredAt;
                }
            }
        };

        DocumentRoutingEvent::query()->select(['id', 'source_id', 'event_key', 'to_office', 'occurred_at'])
            ->where('source_type', $source)->whereIn('event_key', ['received', 'correction_received'])->whereIn('source_id', $ids)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $toExclusive)
            ->chunkById($this->eventChunkSize, function (Collection $events) use ($consume): void {
                foreach ($events as $event) $consume($event);
            }, 'id');

        if ($source === 'conservation') {
            $this->pambReceiptQuery($from, $toExclusive, $filters)->whereIn('conservation_report_submission_id', $ids)
                ->chunkById($this->eventChunkSize, function (Collection $events) use ($consume): void {
                    foreach ($events as $event) $consume($this->pambEventProjection($event, 'received'));
                }, 'id');
        }

        foreach ($firstPenroReceipts as $recordId => $receivedAt) {
            $record = $records[$recordId] ?? null;
            if (! $record) continue;
            $responsible = $this->responsibleOffice($record, $source);
            if ($responsible['category'] !== $category || ! $responsible['office'] || ! isset($rows[$responsible['office']])) continue;
            // Keep the first authoritative PENRO receipt across both providers.
            $this->assessTimeliness($record, $receivedAt, $rows[$responsible['office']]);
        }

        return $cohortDocuments;
    }

    private function pambTimelineProjection(object $event): ?object
    {
        $base = explode(':', (string) $event->stage_key, 2)[0];
        $received = in_array($base, [
            PambRoutingTimelineService::RECORDS_RECEIVED,
            PambRoutingTimelineService::RECEIVED_BY_PENRO,
            PambRoutingTimelineService::RECEIVED_BY_TSD,
            PambRoutingTimelineService::RECEIVED_BY_CDS,
            PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF,
            PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL,
            PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL,
        ], true);
        $handoff = in_array($base, [
            PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
            PambRoutingTimelineService::FORWARDED_TSD_TO_CDS,
            PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF,
            PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS,
        ], true);
        if (! $received && ! $handoff
            && $base !== PambRoutingTimelineService::RELEASED_TO_REGIONAL
            && $base !== PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION) return null;

        $eventKey = $received ? 'received' : ($handoff ? 'forwarded' : match ($base) {
            PambRoutingTimelineService::RELEASED_TO_REGIONAL => 'released',
            PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION => 'returned_for_correction',
        });
        return $this->pambEventProjection($event, $eventKey, $base);
    }

    private function pambEventProjection(object $event, string $eventKey, ?string $stage = null): object
    {
        $stage ??= explode(':', (string) $event->stage_key, 2)[0];
        $office = match ($stage) {
            PambRoutingTimelineService::RECORDS_RECEIVED,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS,
            PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL,
            PambRoutingTimelineService::RELEASED_TO_REGIONAL => 'PENRO Records Unit',
            default => 'Office of the PENRO',
        };
        return (object) [
            'id' => $event instanceof Model ? $event->getKey() : (int) $event->id,
            'source_type' => 'conservation',
            'source_id' => (int) ($event->conservation_report_submission_id ?? $event->source_id),
            'workflow_key' => $event->workflow_key,
            'event_key' => $eventKey,
            'from_office' => $eventKey === 'received' ? 'CENRO Records Unit' : $office,
            'to_office' => $eventKey === 'released' ? 'Regional Office' : $office,
            'occurred_at' => $event->occurred_at,
        ];
    }

    private function matchesReportFilter(Model $record, string $source, array $filters): bool
    {
        $selector = trim((string) ($filters['workflow'] ?? ''));
        if ($selector !== '' && $selector !== 'all' && $selector !== $source.':'.($record->getAttribute('workflow_key') ?: $source)) return false;
        $protectedAreaId = (int) ($filters['protected_area_id'] ?? 0);
        if ($protectedAreaId > 0 && (int) $record->getAttribute('protected_area_id') !== $protectedAreaId) return false;
        if (! empty($filters['reporting_year']) && (int) $record->getAttribute('reporting_year') !== (int) $filters['reporting_year']) return false;
        $reportingPeriod = trim((string) ($filters['reporting_period'] ?? ''));
        if ($reportingPeriod !== '' && $reportingPeriod !== 'all') {
            $period = $record->getAttribute('period_label') ?: $record->getAttribute('reporting_period') ?: $record->getAttribute('semester') ?: $record->getAttribute('quarter');
            if (! $period || mb_strtolower((string) $period) !== mb_strtolower($reportingPeriod)) return false;
        }
        return true;
    }

    private function assessTimeliness(Model $record, mixed $receivedAt, array &$row): void
    {
        $record->setAttribute('date_received_penro', CarbonImmutable::parse($receivedAt, BusinessCalendarService::TIMEZONE)->toDateString());
        $deadline = $record->getAttribute('deadline_submission');
        if (! $deadline) {
            $row['missing_required_evidence']++;
            return;
        }
        $row['timeliness_eligible']++;
        if (CarbonImmutable::parse($receivedAt, BusinessCalendarService::TIMEZONE)->startOfDay()->lessThanOrEqualTo(CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE)->startOfDay())) {
            $row['timeliness_on_time']++;
        } else {
            $row['overdue']++;
        }
    }

    private function addHistoricalEvidence(User $user, string $category, CarbonImmutable $periodStart, CarbonImmutable $cohortEndExclusive, array $filters, array &$rows): void
    {
        if (! in_array(($filters['evidence_type'] ?? 'all'), ['historical', 'all'], true)) return;
        if (! $cohortEndExclusive->greaterThan($periodStart)) return;

        $cohortEndDate = $cohortEndExclusive->subDay()->toDateString();
        $countedDocuments = [];
        HistoricalReportOrigin::query()->orderBy('id')->chunkById(300, function (Collection $origins) use ($user, $category, $periodStart, $cohortEndDate, $filters, &$rows, &$countedDocuments): void {
            $recordsBySource = [];
            foreach ($origins->groupBy('source') as $source => $sourceOrigins) {
                $modelClass = $this->sourceModelClass((string) $source);
                if (! $modelClass || ! $this->canViewSource($user, (string) $source)) continue;
                $query = $modelClass::query()->where('is_historical', true)->whereIn('id', $sourceOrigins->pluck('source_record_id'));
                if ($source !== 'engp') $query->with('protectedArea');
                $query = $source === 'engp'
                    ? $this->organization->scopeDevelopmentQuery($query, $user)
                    : $this->organization->scopeProtectedAreaQuery($query, $user);
                $recordsBySource[$source] = $query->get()->keyBy(fn (Model $model): int => (int) $model->getKey());
            }

            foreach ($origins as $origin) {
                $source = (string) $origin->source;
                $record = $recordsBySource[$source][(int) $origin->source_record_id] ?? null;
                if (! $record || ! $this->matchesReportFilter($record, $source, $filters)) continue;
                $documentKey = $source.':'.(int) $origin->source_record_id;
                if (isset($countedDocuments[$documentKey])) continue;
                $milestones = data_get($origin->original_milestones, 'milestones', []);
                $receipt = $milestones['date_received_penro'] ?? null;
                if (! $receipt || $receipt < $periodStart->toDateString() || $receipt > $cohortEndDate) continue;

                $responsible = $this->responsibleOffice($record, $source);
                if ($responsible['category'] === $category && $responsible['office'] && isset($rows[$responsible['office']])) {
                    $rows[$responsible['office']]['documents_received']++;
                    $rows[$responsible['office']]['manual_received']++;
                    $rows[$responsible['office']]['historical_report_count']++;
                    $record->setAttribute('date_received_penro', $receipt);
                    $this->assessTimeliness($record, $receipt, $rows[$responsible['office']]);
                    $rows[$responsible['office']]['manual_timeliness_reports']++;
                    $countedDocuments[$documentKey] = true;

                    foreach ($this->historicalDateGaps->project($source, $record, $origin) as $basis => $gap) {
                        $prefix = "manual_historical_{$basis}";
                        if ($gap['status'] === 'complete') {
                            $rows[$responsible['office']]["{$prefix}_completed_documents"]++;
                            $rows[$responsible['office']]["{$prefix}_elapsed_calendar_days_total"] += (int) $gap['elapsed_calendar_days'];
                        } elseif ($gap['status'] === 'missing') {
                            $rows[$responsible['office']]["{$prefix}_missing_evidence"]++;
                        } elseif ($gap['status'] === 'invalid') {
                            $rows[$responsible['office']]["{$prefix}_invalid_evidence"]++;
                        } elseif ($gap['status'] === 'unsupported') {
                            $rows[$responsible['office']]["{$prefix}_unsupported"]++;
                        } elseif ($gap['status'] === 'not_applicable') {
                            $rows[$responsible['office']]["{$prefix}_not_applicable"]++;
                        }
                    }

                    if ($category === 'CENRO') {
                        $released = $milestones['date_report_released_cenro'] ?? null;
                        if ($released && $released <= $receipt) {
                            $seconds = max(0, CarbonImmutable::parse($released, BusinessCalendarService::TIMEZONE)->diffInSeconds(CarbonImmutable::parse($receipt, BusinessCalendarService::TIMEZONE)));
                            $rows[$responsible['office']]['manual_historical_tat_completed_documents']++;
                            $rows[$responsible['office']]['manual_historical_tat_elapsed_days_total'] += $seconds / 86400;
                            $rows[$responsible['office']]['manual_historical_tat_elapsed_seconds_total'] += $seconds;
                        } else {
                            $rows[$responsible['office']]['manual_historical_tat_missing_evidence']++;
                        }
                    }
                }

            }
        });
    }

    private function responsibleOffice(Model $record, string $source): array
    {
        if ($source === 'engp') {
            $office = $this->organization->normalizeOffice((string) $record->getAttribute('office'));
            return ['category' => 'CENRO', 'office' => $office];
        }
        $area = $record->relationLoaded('protectedArea') ? $record->protectedArea : null;
        $office = $this->pamoOffice($record, $area);
        return ['category' => 'PAMO', 'office' => $office];
    }

    private function canViewSource(User $user, string $source): bool
    {
        $definition = $this->tracking->source($source);
        $ability = $definition['ability'] ?? null;
        if (is_string($ability) && str_ends_with($ability, '.update')) {
            $ability = substr($ability, 0, -strlen('.update')).'.view';
        }
        if (! $ability || ! $user->can($ability)) return false;
        return $source !== 'engp' || $this->organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT);
    }

    /** @return class-string<Model>|null */
    private function sourceModelClass(string $source): ?string
    {
        $modelClass = $this->tracking->source($source)['model'] ?? null;
        return is_string($modelClass) && is_subclass_of($modelClass, Model::class) ? $modelClass : null;
    }

    private function eventCategory(?string $value): ?string
    {
        $normalized = mb_strtolower(trim((string) $value));
        if (str_contains($normalized, 'pamo')) return 'PAMO';
        if (str_contains($normalized, 'cenro')) return 'CENRO';
        if (str_contains($normalized, 'penro')) return 'PENRO';
        return null;
    }

    private function eventOffice(Model $record, string $source, ?string $category, ?string $eventOffice = null): ?string
    {
        return match ($category) {
            'PENRO' => $this->organization->penroOffices()[0] ?? null,
            'CENRO' => $source === 'engp'
                ? $this->organization->normalizeOffice((string) $record->getAttribute('office'))
                : $this->cenroEventOffice($record, $eventOffice),
            'PAMO' => $this->pamoOffice($record, $record->relationLoaded('protectedArea') ? $record->protectedArea : null),
            default => null,
        };
    }

    private function cenroEventOffice(Model $record, ?string $eventOffice): ?string
    {
        $actualOffice = $this->organization->normalizeOffice($eventOffice);
        if ($actualOffice && in_array($actualOffice, $this->organization->cenroOffices(), true)) return $actualOffice;

        // Generic role labels such as "CENRO CDS Focal Person" do not name
        // an office. For those transitions, use the source's assigned CENRO.
        return $this->organization->normalizeOffice((string) $record->getAttribute('target_office'));
    }

    private function pamoOffice(Model $record, ?ProtectedArea $area): ?string
    {
        $assigned = $area?->pamo;
        if (filled($assigned)) return trim((string) $assigned);
        $protectedAreaId = (int) $record->getAttribute('protected_area_id');
        $assigned = $this->pamoOfficeCache[$protectedAreaId] ?? null;
        return filled($assigned) ? trim((string) $assigned) : null;
    }

    private function primePamoOfficeCache(): void
    {
        $this->pamoOfficeCache = [];
        foreach (User::query()->where('is_active', true)->whereNotNull('protected_area_id')->whereNotNull('office_designated')->get(['protected_area_id', 'office_designated', 'section']) as $assignedUser) {
            if ($this->organization->effectiveCategory($assignedUser) !== OrganizationalAccessService::PAMO) continue;
            $this->pamoOfficeCache[(int) $assignedUser->protected_area_id] = trim((string) $assignedUser->office_designated);
        }
    }

    /** @param array<string,array{source_id:int,office:string,first_receipt_event_key:string,first_receipt_at:string}> $cohortDocuments @param array<int,Model> $records */
    private function addLiveTatIntervalBatch(string $source, array $cohortDocuments, array $records, string $category, CarbonImmutable $periodStart, CarbonImmutable $asOf, array &$rows): void
    {
        $cohortKeys = array_fill_keys(array_keys($cohortDocuments), true);
        $correctionReceiptCohortKeys = [];
        foreach ($cohortDocuments as $key => $cohort) {
            if ($cohort['first_receipt_event_key'] === 'correction_received') $correctionReceiptCohortKeys[$key] = true;
        }
        $cohortIds = collect($cohortDocuments)->pluck('source_id')->unique()->values()->all();

        $pambIds = [];
        if ($source === 'conservation') {
            $pambIds = $this->pambTimelineQuery($cohortIds, $periodStart, $asOf)
                ->distinct()->pluck('conservation_report_submission_id')
                ->map(fn ($id): int => (int) $id)->all();
        }

        $states = [];
        $consume = function (object $event) use ($source, $records, $category, $cohortKeys, $correctionReceiptCohortKeys, &$states, &$rows): void {
            $recordId = (int) $event->source_id;
            $record = $records[$recordId] ?? null;
            if (! $record) return;

            $documentKey = $source.':'.$recordId;
            $toCategory = $this->eventCategory($event->to_office);
            $fromCategory = $this->eventCategory($event->from_office);
            $toOffice = $this->eventOffice($record, $source, $toCategory, $event->to_office);
            $fromOffice = $this->eventOffice($record, $source, $fromCategory, $event->from_office);

            if (in_array($event->event_key, ['received', 'correction_received'], true) && $toCategory === $category && $toOffice && isset($rows[$toOffice])) {
                $key = $documentKey.':'.$toOffice;
                if (! isset($cohortKeys[$key])) return;
                $states[$key] ??= ['office' => $toOffice, 'active' => null, 'segments' => 0.0, 'completed' => false, 'invalid' => false, 'started' => false, 'intervals' => [], 'source_id' => $recordId];
                if ($states[$key]['active'] === null) {
                    $states[$key]['active'] = CarbonImmutable::parse($event->occurred_at, BusinessCalendarService::TIMEZONE);
                }
                $states[$key]['started'] = true;
            }

            // The analytics row represents the full PAMO or CENRO office. A
            // routing step between teams inside that same office is not an
            // office handoff and must not invalidate or close its TAT sample.
            if (in_array($event->event_key, self::HANDOFF_EVENTS, true)
                && $fromCategory === $category && $toCategory !== $category
                && $fromOffice && isset($rows[$fromOffice])) {
                $key = $documentKey.':'.$fromOffice;
                if (! isset($states[$key])) {
                    if (isset($cohortKeys[$key]) && ! isset($correctionReceiptCohortKeys[$key])) {
                        $states[$key] = ['office' => $fromOffice, 'active' => null, 'segments' => 0.0, 'completed' => false, 'invalid' => true, 'started' => false, 'intervals' => [], 'source_id' => $recordId];
                    }
                    return;
                }
                if ($states[$key]['active'] === null) {
                    if (! $states[$key]['started'] && isset($cohortKeys[$key])) $states[$key]['invalid'] = true;
                    return;
                }

                $end = CarbonImmutable::parse($event->occurred_at, BusinessCalendarService::TIMEZONE);
                if ($end->lessThan($states[$key]['active'])) {
                    $states[$key]['invalid'] = true;
                    $states[$key]['active'] = null;
                    $states[$key]['completed'] = false;
                    return;
                }
                $elapsedSeconds = $states[$key]['active']->diffInSeconds($end);
                $states[$key]['segments'] += $elapsedSeconds;
                $states[$key]['intervals'][] = [
                    'accepted_receipt_at' => $states[$key]['active']->toIso8601String(),
                    'accepted_handoff_at' => $end->toIso8601String(),
                    'elapsed_seconds' => $elapsedSeconds,
                ];
                $states[$key]['active'] = null;
                $states[$key]['completed'] = true;
            }
        };

        $genericEvents = DB::table('document_routing_events')
            ->select(['id', 'source_id', 'event_key', 'from_office', 'to_office', 'occurred_at'])
            ->where('source_type', $source)->whereIn('source_id', $cohortIds)
            ->where('occurred_at', '>=', $periodStart)
            ->where('occurred_at', '<', $asOf->addDay()->startOfDay());
        if ($pambIds !== []) $genericEvents->whereNotIn('source_id', $pambIds);
        $this->forEachOrderedEventPage($genericEvents, 'source_id', $consume);

        if ($source === 'conservation' && $pambIds !== []) {
            $pambEvents = $this->pambTimelineQuery($pambIds, $periodStart, $asOf);
            $this->forEachOrderedEventPage($pambEvents, 'conservation_report_submission_id', function (object $event) use ($consume): void {
                $projection = $this->pambTimelineProjection($event);
                if ($projection) $consume($projection);
            });
        }

        foreach ($states as $state) {
            $office = $state['office'];
            $sourceRecord = $records[(int) $state['source_id']] ?? null;
            if (! isset($rows[$office])) continue;
            if ($state['invalid']) $rows[$office]['tat_missing_evidence']++;
            if ($state['active'] instanceof CarbonImmutable) {
                $rows[$office]['pending']++;
                $seconds = max(0, $state['active']->diffInSeconds($asOf->endOfDay()));
                $rows[$office]['tat_pending_elapsed_days_total'] += $seconds / 86400;
                continue;
            }
            if ($state['completed'] && ! $state['invalid']) {
                $rows[$office]['tat_completed_documents']++;
                $rows[$office]['tat_elapsed_days_total'] += $state['segments'] / 86400;
                $rows[$office]['tat_elapsed_seconds_total'] += $state['segments'];
                $workflowKey = (string) ($sourceRecord?->getAttribute('workflow_key') ?? '');
                if ($workflowKey === '') {
                    $workflowKey = match ($source) {
                        'bms' => 'bms', 'bams' => 'bams', 'imea' => 'imea',
                        'imea-maintenance' => 'imea_facility_maintenance',
                        'aws' => 'automated_weather_station', 'ipaf-management' => 'ipaf_management',
                        'revenue' => 'revenue_collection',
                        default => $source,
                    };
                }
                $rows[$office]['tat_interval_details'][] = [
                    'source_reference' => $this->tatSourceReference($source, (int) $state['source_id']),
                    'source_key' => $source,
                    'source_id' => (int) $state['source_id'],
                    'workflow_key' => $workflowKey,
                    'elapsed_seconds' => $state['segments'],
                    'intervals' => $state['intervals'],
                ];
            }
        }
    }
    private function tatSourceReference(string $source, int $sourceId): string
    {
        $label = match ($source) {
            'engp' => 'ENGP report',
            'conservation' => 'Conservation report',
            'bms' => 'BMS report',
            default => ucwords(str_replace('_', ' ', $source)).' report',
        };

        return $label.' #'.$sourceId;
    }

    /** Stream an event query in occurrence order with a unique id tie-breaker. */
    private function forEachOrderedEventPage(QueryBuilder $query, string $sourceColumn, Closure $consume): void
    {
        $lastSourceId = null;
        $lastOccurredAt = null;
        $lastEventId = null;

        do {
            $pageQuery = clone $query;
            if ($lastSourceId !== null) {
                $pageQuery->where(function (QueryBuilder $cursor) use ($sourceColumn, $lastSourceId, $lastOccurredAt, $lastEventId): void {
                    $cursor->where($sourceColumn, '>', $lastSourceId)
                        ->orWhere(function (QueryBuilder $sameSource) use ($sourceColumn, $lastSourceId, $lastOccurredAt, $lastEventId): void {
                            $sameSource->where($sourceColumn, $lastSourceId)
                                ->where(function (QueryBuilder $timeCursor) use ($lastOccurredAt, $lastEventId): void {
                                    $timeCursor->where('occurred_at', '>', $lastOccurredAt)
                                        ->orWhere(function (QueryBuilder $sameTime) use ($lastOccurredAt, $lastEventId): void {
                                            $sameTime->where('occurred_at', $lastOccurredAt)->where('id', '>', $lastEventId);
                                        });
                                });
                        });
                });
            }
            $page = $pageQuery->orderBy($sourceColumn)->orderBy('occurred_at')->orderBy('id')->limit($this->eventChunkSize)->get();
            foreach ($page as $event) $consume($event);
            $last = $page->last();
            if (! $last) break;
            $lastSourceId = (int) $last->source_id;
            $lastOccurredAt = (string) $last->occurred_at;
            $lastEventId = (int) $last->id;
        } while ($page->count() === $this->eventChunkSize);
    }

    private function pambTimelineQuery(array $recordIds, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        $bases = [
            PambRoutingTimelineService::RECORDS_RECEIVED,
            PambRoutingTimelineService::RECEIVED_BY_PENRO,
            PambRoutingTimelineService::RECEIVED_BY_TSD,
            PambRoutingTimelineService::RECEIVED_BY_CDS,
            PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF,
            PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL,
            PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL,
            PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD,
            PambRoutingTimelineService::FORWARDED_TSD_TO_CDS,
            PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF,
            PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS,
            PambRoutingTimelineService::RELEASED_TO_REGIONAL,
            PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION,
        ];
        $query = DB::table('pamb_routing_events')
            ->select(['id', 'conservation_report_submission_id as source_id', 'conservation_report_submission_id', 'workflow_key', 'stage_key', 'occurred_at'])
            ->whereIn('conservation_report_submission_id', $recordIds)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to->addDay()->startOfDay());

        return $query->where(function (QueryBuilder $stages) use ($bases): void {
            foreach ($bases as $base) $stages->orWhere('stage_key', $base)->orWhere('stage_key', 'like', $base.':%');
        });
    }

    private function emptyRow(string $category, string $office): array
    {
        return [
            'office_category' => $category,
            'office' => $office,
            'timeliness_on_time' => 0,
            'timeliness_eligible' => 0,
            'overdue' => 0,
            'missing_required_evidence' => 0,
            'documents_received' => 0,
            'event_derived_received' => 0,
            'manual_received' => 0,
            'historical_report_count' => 0,
            'tat_completed_documents' => 0,
            'tat_elapsed_days_total' => 0.0,
            'tat_elapsed_seconds_total' => 0.0,
            'tat_pending_elapsed_days_total' => 0.0,
            'tat_missing_evidence' => 0,
            'tat_interval_details' => [],
            'manual_historical_tat_completed_documents' => 0,
            'manual_historical_tat_elapsed_days_total' => 0.0,
            'manual_historical_tat_elapsed_seconds_total' => 0.0,
            'manual_historical_tat_average_elapsed_days' => null,
            'manual_historical_tat_missing_evidence' => 0,
            'manual_historical_preparation_completed_documents' => 0,
            'manual_historical_preparation_elapsed_calendar_days_total' => 0,
            'manual_historical_preparation_average_elapsed_calendar_days' => null,
            'manual_historical_preparation_missing_evidence' => 0,
            'manual_historical_preparation_invalid_evidence' => 0,
            'manual_historical_preparation_unsupported' => 0,
            'manual_historical_preparation_not_applicable' => 0,
            'manual_historical_processing_completed_documents' => 0,
            'manual_historical_processing_elapsed_calendar_days_total' => 0,
            'manual_historical_processing_average_elapsed_calendar_days' => null,
            'manual_historical_processing_missing_evidence' => 0,
            'manual_historical_processing_invalid_evidence' => 0,
            'manual_historical_processing_unsupported' => 0,
            'manual_historical_processing_not_applicable' => 0,
            'manual_historical_handoff_completed_documents' => 0,
            'manual_historical_handoff_elapsed_calendar_days_total' => 0,
            'manual_historical_handoff_average_elapsed_calendar_days' => null,
            'manual_historical_handoff_missing_evidence' => 0,
            'manual_historical_handoff_invalid_evidence' => 0,
            'manual_historical_handoff_unsupported' => 0,
            'manual_historical_handoff_not_applicable' => 0,
            'tat_average_elapsed_days' => null,
            'pending' => 0,
            'tat_compliance_eligible' => 0,
            'tat_compliance_missing_limit' => 0,
            'manual_timeliness_reports' => 0,
            'score' => null,
        ];
    }

    /** @return array<string,OfficeTatConfigurationVersion> */
    private function configurationFor(string $category, array $offices, string $periodKey): array
    {
        $query = OfficeTatConfigurationVersion::query()->where('office_category', $category)->where('period_key', $periodKey);
        if (Schema::hasColumn('office_tat_configuration_versions', 'organizational_office_id') && $category === 'PAMO') {
            $officeIds = OrganizationalOffice::query()->where('office_type', 'pamo')->whereIn('name', $offices)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $query->where(fn ($scope) => $scope->whereIn('office', $offices)->orWhereIn('organizational_office_id', $officeIds));
            $namesById = OrganizationalOffice::query()->where('office_type', 'pamo')->whereIn('id', $officeIds)->pluck('name', 'id');
        } else {
            $query->whereIn('office', $offices);
            $namesById = collect();
        }
        $result = [];
        foreach ($query->orderBy('version')->get() as $configuration) {
            $name = $configuration->organizational_office_id !== null
                ? ($namesById[(int) $configuration->organizational_office_id] ?? null)
                : $configuration->office;
            if ($name !== null && in_array($name, $offices, true)) $result[$name] = $configuration;
        }
        return $result;
    }

    /** @return list<string> */
    private function officesFor(string $category, User $user): array
    {
        abort_unless(in_array($category, ['PAMO', 'CENRO'], true), 422);
        $global = $this->organization->isGlobal($user);
        $penroReviewer = ! $global && $this->isPenroOfficeTatReviewer($user);

        if ($category === 'PAMO' && ($global || $penroReviewer)) {
            return User::query()->where('is_active', true)->whereRaw('UPPER(section) = ?', [OrganizationalAccessService::PAMO])->whereNotNull('office_designated')->pluck('office_designated')
                ->merge(ProtectedArea::query()->whereNotNull('pamo')->pluck('pamo'))
                ->map(fn ($office) => trim((string) $office))->filter()->unique()->sort()->values()->all();
        }

        if (! $global && ! $penroReviewer) {
            $name = $category === 'PAMO'
                ? $this->pamoOffice(new ConservationReportSubmission(['protected_area_id' => $user->protected_area_id]), ProtectedArea::query()->find($user->protected_area_id))
                : $this->organization->normalizeOffice($user->office_designated);
            return $name ? [$name] : [];
        }

        $active = OrganizationalOffice::query()->where('is_active', true)->orderBy('name')->pluck('name')
            ->filter(fn (string $office): bool => str_starts_with(mb_strtolower($office), 'cenro'))
            ->map(fn (string $office): string => $this->organization->normalizeOffice($office) ?: $office)
            ->unique()->values()->all();

        return $penroReviewer
            ? array_values(array_intersect($this->organization->cenroOffices(), $active))
            : $active;
    }

    private function assertCategoryAccess(User $user, string $category): void
    {
        abort_unless(in_array($category, ['PAMO', 'CENRO'], true), 422);
        if ($this->organization->isGlobal($user)) return;
        $effective = $this->organization->effectiveCategory($user);
        $allowed = match ($category) {
            'PAMO' => ($effective === OrganizationalAccessService::PAMO && $this->organization->canViewPamoPilotWorkflow($user))
                || ($this->isPenroOfficeTatReviewer($user) && in_array('conservation', $this->organization->effectiveUnits($user), true)),
            'CENRO' => in_array($effective, [OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_RECORDS], true)
                || ($this->isPenroOfficeTatReviewer($user) && in_array('development', $this->organization->effectiveUnits($user), true)),
            default => false,
        };
        abort_unless($allowed, 403);
    }

    private function isPenroOfficeTatReviewer(User $user): bool
    {
        return $user->can('office-tat.view')
            && in_array($this->organization->effectiveCategory($user), [
                OrganizationalAccessService::PENRO_CHIEF,
                OrganizationalAccessService::PENRO_FOCAL,
                OrganizationalAccessService::PENRO_RECORDS,
                OrganizationalAccessService::OFFICE_PENRO,
                OrganizationalAccessService::PENRO_TSD_CHIEF,
            ], true);
    }

    /** @return list<array{value:string,label:string}> */
    private function workflowOptions(User $user): array
    {
        $options = [];
        if ($this->canViewSource($user, 'conservation')) {
            foreach ($this->conservation->all() as $workflow) $options[] = ['value' => 'conservation:'.$workflow['key'], 'label' => $workflow['label']];
        }
        if ($this->canViewSource($user, 'engp')) {
            foreach ($this->engp->all() as $workflow) $options[] = ['value' => 'engp:'.$workflow['key'], 'label' => $workflow['label']];
        }
        $sourceModels = $this->tracking->reportSourceModels();
        foreach (app(\App\Services\OfficeTat\LiveReportSourceRegistry::class)->native() as $source) {
            if (! $this->canViewSource($user, $source)) continue;
            $modelClass = $sourceModels[$source] ?? null;
            if (! is_string($modelClass)) continue;
            $definition = $this->tracking->source($source);
            if (! $definition) continue;
            $record = new $modelClass;
            $label = $this->tracking->archiveReportMetadata($source, $record, $definition)['module'];
            $options[] = ['value' => $source.':'.$source, 'label' => $label];
        }
        if ($this->organization->effectiveCategory($user) === OrganizationalAccessService::PAMO
            && (int) $user->protected_area_id > 0) {
            $nativeSources = app(\App\Services\Pamo\PamoNativeSourceRegistry::class);
            $registeredSources = $this->tracking->reportSourceModels();
            if ($nativeSources->isSchemaReady()) {
                foreach ($nativeSources->all() as $workflow => $native) {
                    if ($nativeSources->isEligible($workflow) && isset($registeredSources[$native['source']])) {
                        $options[] = ['value' => $native['source'].':'.$native['source'], 'label' => $native['label']];
                    }
                }
            }
        }
        return collect($options)->keyBy('value')->values()->all();
    }

    public function canonicalPeriodKey(mixed $input): string
    {
        $today = CarbonImmutable::now(BusinessCalendarService::TIMEZONE);
        $value = is_string($input) ? trim($input) : '';

        if (preg_match('/^(\d{4})(?:-H([12]))?$/', $value, $match)) {
            $year = (int) $match[1];
            if ($year >= 2000 && $year <= $today->year) return $value;
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/i', $value, $match)) {
            $year = (int) $match[1];
            if ($year >= 2000 && $year <= $today->year) {
                return $year.'-H'.((int) $match[2] <= 2 ? '1' : '2');
            }
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $match)) {
            $year = (int) $match[1];
            $month = (int) $match[2];
            if ($year >= 2000 && $year <= $today->year && $month >= 1 && $month <= 12) {
                return $year.'-H'.($month <= 6 ? '1' : '2');
            }
        }

        return $today->year.'-H'.($today->month <= 6 ? '1' : '2');
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    private function periodBounds(string $key): array
    {
        $year = (int) substr($key, 0, 4);
        $semester = strlen($key) > 4 ? substr($key, -2) : null;
        $startMonth = $semester === 'H2' ? 7 : 1;
        $start = CarbonImmutable::create($year, $startMonth, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE);
        $endExclusive = $semester === 'H1'
            ? $start->addMonths(6)
            : CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, BusinessCalendarService::TIMEZONE);

        return [$start, $endExclusive];
    }

    /** @return array{type:string,year:int,semester:?string,label:string} */
    private function assessmentPeriod(string $key): array
    {
        $year = (int) substr($key, 0, 4);
        $semester = strlen($key) > 4 ? substr($key, -2) : null;

        return [
            'type' => $semester ? 'semestral' : 'annual',
            'year' => $year,
            'semester' => $semester,
            'label' => $this->periodLabel($key),
        ];
    }

    private function periodLabel(string $key): string
    {
        $year = (int) substr($key, 0, 4);

        return match (strlen($key) > 4 ? substr($key, -2) : null) {
            'H1' => '1st Semester '.$year.' · Jan–Jun',
            'H2' => '2nd Semester '.$year.' · Jul–Dec',
            default => 'Annual '.$year.' · Jan–Dec',
        };
    }

    private function isCanonicalAssessmentPeriod(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})(?:-H[12])?$/', $value, $match)) return false;
        $year = (int) $match[1];
        $currentYear = CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->year;

        return $year >= 2000 && $year <= $currentYear && $this->canonicalPeriodKey($value) === $value;
    }

    private function parseAsOf(mixed $input): CarbonImmutable
    {
        try { $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $input, BusinessCalendarService::TIMEZONE); }
        catch (\Throwable) { $date = null; }
        $today = CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->startOfDay();
        if (! $date || $date->format('Y-m-d') !== (string) $input || $date->greaterThan($today)) return $today;
        return $date->startOfDay();
    }

    private function authorizedProtectedAreas(User $user): array
    {
        return $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')
            ->orderBy('name')->get(['id', 'name', 'short_name'])
            ->map(fn (ProtectedArea $area): array => ['id' => (int) $area->id, 'name' => (string) $area->name, 'short_name' => $area->short_name])->all();
    }
}
