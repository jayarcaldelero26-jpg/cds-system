<?php

use App\Models\ConservationReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingAttachment;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\Notifications\EdatsInAppNotificationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class CountingSubmissionCalendar extends BusinessCalendarService
{
    /** @var array<string, int> */
    public array $inputs = [];

    /** @var array<string, array<string, int>> */
    public array $inputsByCaller = [];

    public int $calls = 0;

    public float $elapsedMs = 0.0;

    public function resetProfile(): void
    {
        $this->inputs = [];
        $this->inputsByCaller = [];
        $this->calls = 0;
        $this->elapsedMs = 0.0;
    }

    public function workingDaysBetween(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        string $countingSemantics = 'after_through',
        ?string $office = null,
        ?array $workingWeekdays = null,
    ): int {
        $start = $startDate instanceof CarbonInterface ? $startDate->toDateString() : CarbonImmutable::parse($startDate, self::TIMEZONE)->toDateString();
        $end = $endDate instanceof CarbonInterface ? $endDate->toDateString() : CarbonImmutable::parse($endDate, self::TIMEZONE)->toDateString();
        $key = implode('|', [$start, $end, mb_strtolower(trim((string) $office)), $countingSemantics, implode(',', $workingWeekdays ?? self::PAMB_WORKING_WEEKDAYS)]);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = ($trace[1]['class'] ?? 'unknown').'::'.($trace[1]['function'] ?? 'unknown');
        $this->calls++;
        $this->inputs[$key] = ($this->inputs[$key] ?? 0) + 1;
        $this->inputsByCaller[$caller] ??= [];
        $this->inputsByCaller[$caller][$key] = ($this->inputsByCaller[$caller][$key] ?? 0) + 1;
        $started = hrtime(true);
        try {
            return parent::workingDaysBetween($startDate, $endDate, $countingSemantics, $office, $workingWeekdays);
        } finally {
            $this->elapsedMs += (hrtime(true) - $started) / 1_000_000;
        }
    }

    /** @return array{calls:int,unique_inputs:int,repeated_calls:int,elapsed_ms:float} */
    public function profile(): array
    {
        $callers = [];
        foreach ($this->inputsByCaller as $caller => $inputs) {
            $calls = array_sum($inputs);
            $callers[$caller] = [
                'calls' => $calls,
                'unique_inputs' => count($inputs),
                'repeated_calls' => $calls - count($inputs),
            ];
        }

        return [
            'calls' => $this->calls,
            'unique_inputs' => count($this->inputs),
            'repeated_calls' => $this->calls - count($this->inputs),
            'elapsed_ms' => round($this->elapsedMs, 3),
            'callers' => $callers,
        ];
    }

    /** @param iterable<CountingSubmissionCalendar> $profiles @return array<string,mixed> */
    public static function aggregate(iterable $profiles): array
    {
        $summary = ['calls' => 0, 'unique_inputs' => 0, 'repeated_calls' => 0, 'elapsed_ms' => 0.0, 'callers' => []];
        foreach ($profiles as $profile) {
            $sample = $profile->profile();
            foreach (['calls', 'unique_inputs', 'repeated_calls'] as $key) {
                $summary[$key] += $sample[$key];
            }
            $summary['elapsed_ms'] += $sample['elapsed_ms'];
            foreach ($sample['callers'] as $caller => $values) {
                $summary['callers'][$caller] ??= ['calls' => 0, 'unique_inputs' => 0, 'repeated_calls' => 0];
                foreach (['calls', 'unique_inputs', 'repeated_calls'] as $key) {
                    $summary['callers'][$caller][$key] += $values[$key];
                }
            }
        }
        $summary['elapsed_ms'] = round($summary['elapsed_ms'], 3);

        return $summary;
    }
}

function trackingPerformanceReport(ProtectedArea $area, User $actor, int $index): ConservationReportSubmission
{
    return ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Query performance meeting '.$index,
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-03-01',
        'date_accomplished' => '2026-03-02',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
}

function trackingLegacyPambOverride(ConservationReportSubmission $record, User $actor, ProtectedArea $area, string $stageKey): PambRoutingEvent
{
    $event = $record->routingEvents()->create([
        'workflow_key' => $record->workflow_key,
        'stage_key' => $stageKey,
        'occurred_at' => now(),
        'recorded_by' => $actor->id,
    ]);
    \App\Models\SubmissionRoutingOverride::query()->create([
        'source' => 'conservation', 'source_record_id' => $record->id, 'engine' => 'pamb',
        'action_key' => $stageKey, 'event_key' => $stageKey,
        'actual_actor_user_id' => $actor->id, 'actual_actor_category' => 'admin',
        'overridden_accountable_category' => 'PENRO Records', 'overridden_office' => 'PENRO Davao Oriental',
        'protected_area_id' => $area->id, 'reason' => 'Isolated legacy PAMB override fixture',
        'authentication_method' => 'password', 'previous_stage' => 'cenro_release',
        'resulting_stage' => $stageKey, 'metadata' => ['legacy_pamb_event_id' => $event->id],
    ]);

    return $event;
}

test('Submission Tracking source reads stay bounded as same-source rows increase', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Tracking Query Performance PA', 'short_name' => 'TQPP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $first = trackingPerformanceReport($area, $actor, 1);
    $trackingNumbers = app(\App\Services\Reports\ReportTrackingNumberService::class);
    $trackingNumbers->ensureFor(collect([['record' => $first, 'key' => 'conservation']]));

    $capture = false;
    $sourceReads = 0;
    $perRecordRefetches = 0;
    DB::listen(function (QueryExecuted $query) use (&$capture, &$sourceReads, &$perRecordRefetches): void {
        if (! $capture) return;
        $sql = strtolower($query->sql);
        if (str_starts_with(trim($sql), 'select * from "conservation_report_submissions"')) $sourceReads++;
        if (str_contains($sql, 'conservation_report_submissions') && str_contains($sql, 'where "conservation_report_submissions"."id" = ?')) {
            $perRecordRefetches++;
        }
    });

    $tracking = app(SubmissionTrackingService::class);
    $this->actingAs($actor);
    $capture = true;
    $small = $tracking->records([], null, false);
    $smallReads = $sourceReads;
    $capture = false;

    $moreReports = collect();
    for ($index = 2; $index <= 26; $index++) $moreReports->push(trackingPerformanceReport($area, $actor, $index));
    $trackingNumbers->ensureFor($moreReports->map(fn (ConservationReportSubmission $record): array => ['record' => $record, 'key' => 'conservation']));
    $sourceReads = 0;
    $perRecordRefetches = 0;
    $capture = true;
    $large = $tracking->records([], null, false);
    $largeReads = $sourceReads;
    $capture = false;
    $sourceReads = 0;
    $capture = true;
    $workspace = $tracking->workspaceQueues();
    $workspaceReads = $sourceReads;
    $capture = false;
    expect($small)->toHaveCount(1)
        ->and($large)->toHaveCount(26)
        ->and($smallReads)->toBe($largeReads)
        ->and($workspaceReads)->toBe(1)
        ->and(array_keys($workspace))->toBe(['incoming', 'outgoing', 'history'])
        ->and($perRecordRefetches)->toBe(0);
});

test('Submission Tracking presentation query families batch instead of growing per PAMB row', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Tracking Presentation Query PA', 'short_name' => 'TPQP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    for ($index = 1; $index <= 10; $index++) trackingPerformanceReport($area, $actor, $index);
    $firstRecord = ConservationReportSubmission::query()->firstOrFail();
    trackingLegacyPambOverride($firstRecord, $actor, $area, PambRoutingTimelineService::RECORDS_RECEIVED);
    $numbers = app(\App\Services\Reports\ReportTrackingNumberService::class);
    $numbers->ensureFor(ConservationReportSubmission::all()->map(fn (ConservationReportSubmission $record): array => ['record' => $record, 'key' => 'conservation']));

    $capture = false;
    $counts = [];
    DB::listen(function (QueryExecuted $query) use (&$capture, &$counts): void {
        if (! $capture) return;
        $sql = strtolower($query->sql);
        $counts['total'] = ($counts['total'] ?? 0) + 1;
        if (str_contains($sql, 'submission_routing_overrides')) $counts['overrides'] = ($counts['overrides'] ?? 0) + 1;
        if (str_contains($sql, 'submission_routing_attachments') && str_contains($sql, 'where 0 = 1')) $counts['empty_attachments'] = ($counts['empty_attachments'] ?? 0) + 1;
        if (str_contains($sql, 'audit_logs') && str_contains($sql, 'where "event_type" = ?') && str_contains($sql, '"entity_id" = ?')) $counts['per_record_audits'] = ($counts['per_record_audits'] ?? 0) + 1;
        if (str_contains($sql, 'select "id" from "protected_areas"') && str_contains($sql, '"name" in')) $counts['canonical_pa_identity'] = ($counts['canonical_pa_identity'] ?? 0) + 1;
    });

    $measure = function (int $expected) use (&$capture, &$counts, $firstRecord): void {
        app()->forgetScopedInstances();
        $counts = [];
        $capture = true;
        $rows = app(SubmissionTrackingService::class)->records(['program' => 'conservation'], null, false);
        $capture = false;
        expect($rows)->toHaveCount($expected)
            ->and($rows->every(fn (array $row): bool => $row['source'] === 'conservation'))->toBeTrue()
            ->and(collect(data_get($rows->firstWhere('source_id', $firstRecord->id), 'routing.routing_history', []))->first(fn (array $event): bool => $event['event_type'] === 'received')['administrative_override'])->toBeTrue()
            ->and($counts['overrides'] ?? 0)->toBeLessThanOrEqual(1)
            ->and($counts['empty_attachments'] ?? 0)->toBe(0)
            ->and($counts['per_record_audits'] ?? 0)->toBe(0)
            ->and($counts['canonical_pa_identity'] ?? 0)->toBeLessThanOrEqual(1);
    };

    $this->actingAs($actor);
    $measure(10);
    for ($index = 11; $index <= 60; $index++) trackingPerformanceReport($area, $actor, $index);
    $numbers->ensureFor(ConservationReportSubmission::all()->map(fn (ConservationReportSubmission $record): array => ['record' => $record, 'key' => 'conservation']));
    $measure(60);
});

test('empty routing event attachment sets do not issue placeholder queries', function (): void {
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'submission_routing_attachments')) $queries++;
    });

    $attachments = app(\App\Services\SubmissionTracking\RoutingAttachmentService::class);
    expect($attachments->forDocumentEvents([]))->toBe([])
        ->and($attachments->forPambEvents([]))->toBe([])
        ->and($queries)->toBe(0);
});

test('canonical PA identity is request scoped and an absent result is refreshed at the next lifecycle', function (): void {
    $actor = User::factory()->create(['section' => 'CDS']);
    $canonicalArea = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $report = trackingPerformanceReport($canonicalArea, $actor, 9001);
    $canonicalArea->delete();
    $normalArea = ProtectedArea::create([
        'name' => 'Lifecycle Ordinary PA', 'short_name' => 'LOPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $normalReport = trackingPerformanceReport($normalArea, $actor, 9002);

    $identityQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$identityQueries): void {
        if (str_contains(strtolower($query->sql), 'select "id" from "protected_areas"')
            && str_contains(strtolower($query->sql), '"name" in')) $identityQueries++;
    });

    $first = app(ProtectedAreaRoutingPolicy::class);
    expect($first)->toBe(app(ProtectedAreaRoutingPolicy::class))
        ->and($first->isDirectPenro($report))->toBeFalse()
        ->and($first->isDirectPenro($report))->toBeFalse()
        ->and($first->isDirectPenro($normalReport))->toBeFalse()
        ->and($identityQueries)->toBe(1);

    $canonicalArea->restore();
    // The current lifecycle retains only the already-resolved absence. Laravel's
    // scoped-instance flush is the same container boundary used to reset scoped
    // bindings between long-lived request/job lifecycles.
    expect($first->isDirectPenro($report))->toBeFalse();
    app()->forgetScopedInstances();
    $next = app(ProtectedAreaRoutingPolicy::class);
    expect($next)->not->toBe($first)
        ->and($next->isDirectPenro($report))->toBeTrue()
        ->and($identityQueries)->toBe(2);
});

test('PAMB overrides stay attached to the conservation source when another source shares its ID', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Tracking Source Collision PA', 'short_name' => 'TSCPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $pamb = trackingPerformanceReport($area, $actor, 9101);
    $bms = new BmsReportSubmission([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'ID collision',
        'document_type' => 'Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $bms->id = $pamb->id;
    $bms->save();
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation', 'source_id' => $pamb->id, 'workflow_key' => 'regular_pamb',
        'event_key' => 'forwarded', 'from_stage' => 'cenro_preparation', 'to_stage' => 'transit_to_cenro_chief',
        'occurred_at' => now(), 'recorded_by' => $actor->id,
        'metadata' => ['action_key' => 'forward_to_cenro_chief', 'administrative_override' => true,
            'override_for_category' => 'CENRO_CDS_FOCAL', 'override_for_office' => 'CENRO Mati'],
    ]);

    $this->actingAs($actor);
    $rows = app(SubmissionTrackingService::class)->records(['program' => 'conservation'], null, false);
    $pambRow = $rows->first(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $pamb->id);
    $bmsRow = $rows->first(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $bms->id);

    expect($pambRow)->not->toBeNull()
        ->and($bmsRow)->not->toBeNull()
        ->and($pambRow['source_id'])->toBe($bmsRow['source_id'])
        ->and(collect($pambRow['routing']['routing_history'])->first()['administrative_override'])->toBeTrue()
        ->and(collect($bmsRow['routing']['routing_history'])->every(fn (array $event): bool => ! ($event['administrative_override'] ?? false)))->toBeTrue();
});

test('PAMB override batching crosses the 500-ID boundary without losing matching metadata', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Tracking Override Batch PA', 'short_name' => 'TOBPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $timestamp = now();
    $insert = [];
    for ($index = 1; $index <= 501; $index++) {
        $insert[] = [
            'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
            'activity_name' => 'Override batch '.$index, 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
            'date_conducted' => '2026-03-01', 'date_accomplished' => '2026-03-02',
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ];
        if (count($insert) === 100 || $index === 501) {
            DB::table('conservation_report_submissions')->insert($insert);
            $insert = [];
        }
    }
    $ids = ConservationReportSubmission::query()->orderBy('id')->pluck('id');
    foreach ([$ids->first(), $ids->last()] as $id) {
        $record = ConservationReportSubmission::query()->findOrFail($id);
        trackingLegacyPambOverride($record, $actor, $area, PambRoutingTimelineService::RECORDS_RECEIVED);
    }

    $overrideQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$overrideQueries): void {
        if (str_contains(strtolower($query->sql), 'from "submission_routing_overrides"')) $overrideQueries++;
    });
    $this->actingAs($actor);
    $rows = app(SubmissionTrackingService::class)->records(['program' => 'conservation'], null, false);
    $overrideHistory = fn (int $id): ?array => collect(data_get($rows->firstWhere('source_id', $id), 'routing.routing_history', []))
        ->first(fn (array $event): bool => $event['event_type'] === 'received');

    expect($ids)->toHaveCount(501)
        ->and($rows)->toHaveCount(501)
        ->and($overrideHistory((int) $ids->first())['administrative_override'])->toBeTrue()
        ->and($overrideHistory((int) $ids->get(250)))->toBeNull()
        ->and($overrideHistory((int) $ids->last())['administrative_override'])->toBeTrue()
        ->and($overrideQueries)->toBe(2);
});

test('tracking page omits snapshot queues while retaining complete authorized workspace and partial reload props', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Tracking Page Contract PA', 'short_name' => 'TPCPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $ids = [];
    $baseDate = \Carbon\CarbonImmutable::parse('2026-01-01');
    for ($index = 1; $index <= 60; $index++) {
        $report = trackingPerformanceReport($area, $actor, $index);
        $report->update(['date_accomplished' => $baseDate->addDays($index - 1)->toDateString()]);
        $ids[] = $report->id;
    }

    $this->actingAs($actor);
    $pageResponse = $this->get(route('submission-tracking.index', ['page' => 2]))->assertOk();
    $pageProps = $pageResponse->inertiaProps();
    $incoming = $pageProps['workspaceQueues']['incoming'];
    $incomingIds = array_map(fn (array $row): int => (int) $row['source_id'], $incoming);
    expect($pageProps)->not->toHaveKey('queues')
        ->and($incoming)->toHaveCount(60)
        ->and($incomingIds)->toBe(array_reverse($ids))
        ->and($pageProps['pagination'])->toMatchArray([
            'current_page' => 2, 'per_page' => 25, 'total' => 60, 'last_page' => 3,
            'from' => 26, 'to' => 50, 'has_more' => true,
        ])
        ->and($incoming[0])->toHaveKeys(['source', 'source_id', 'routing', 'routing_timeline', 'mov_processing', 'can_transition']);

    $filteredResponse = $this->get(route('submission-tracking.index', ['search' => 'meeting 60']))->assertOk();
    $filteredProps = $filteredResponse->inertiaProps();
    expect($filteredProps)->not->toHaveKey('queues')
        ->and($filteredProps['pagination']['total'])->toBe(1)
        ->and(collect($filteredProps['workspaceQueues'])->flatten(1)->pluck('source_id')->map(fn ($id): int => (int) $id)->all())->toBe([$ids[59]]);

    $version = $pageResponse->inertiaPage()['version'];
    $partial = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'SubmissionTracking/Index',
        'X-Inertia-Partial-Data' => 'workspaceQueues,trackingContext,pagination',
    ])->get(route('submission-tracking.index', ['page' => 2]))->assertOk();
    $partialPage = json_decode($partial->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $partialProps = $partialPage['props'];
    expect($partial->headers->get('X-Inertia'))->toBe('true')
        ->and($partialProps)->toHaveKey('workspaceQueues')
        ->and($partialProps)->toHaveKey('trackingContext')
        ->and($partialProps)->toHaveKey('pagination')
        ->and($partialProps)->not->toHaveKey('queues')
        ->and($partialProps['workspaceQueues']['incoming'])->toHaveCount(60);
});

test('local opt-in trace profiles the real Submission Tracking controller without changing its rows', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Trace Fixture PA', 'short_name' => 'TFPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $fixture = trackingPerformanceReport($area, $actor, 9901);
    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
        ->get('http://cds-system.test/submission-tracking?view=outgoing&__cds_perf=1')
        ->assertOk();

    $props = $response->inertiaProps();
    $sourceIds = collect($props['workspaceQueues'])->flatten(1)->pluck('source_id')->map(fn ($id): int => (int) $id)->all();
    $timing = $response->headers->get('Server-Timing');
    expect($response->headers->get('X-CDS-Perf-Route'))->toBe('submission-tracking.index')
        ->and($response->headers->get('X-CDS-Perf-Actor-Category'))->not->toBeEmpty()
        ->and($response->headers->get('X-CDS-Perf-Context'))->toContain('view=outgoing')
        ->and($response->headers->get('X-CDS-Perf-Counts'))->toContain('projected_rows=1')
        ->and($sourceIds)->toContain($fixture->id)
        ->and($timing)->toContain('st_pagination;dur=')
        ->and($timing)->toContain('st_source_load;dur=')
        ->and($timing)->toContain('st_metadata;dur=')
        ->and($timing)->toContain('st_routing_presentation;dur=')
        ->and($timing)->toContain('st_pamb_timeline;dur=')
        ->and($timing)->toContain('st_mov_presentation;dur=')
        ->and($timing)->toContain('st_document_routing;dur=')
        ->and($timing)->toContain('st_queue_projection;dur=')
        ->and($timing)->toContain('st_filter_options;dur=')
        ->and($timing)->toContain('st_context;dur=')
        ->and($timing)->toContain('st_response_build;dur=');
});

test('local navigation trace covers the History and Homestay page request families', function (): void {
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);

    $history = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
        ->get('http://cds-system.test/submission-tracking?view=history&__cds_perf=1')
        ->assertOk();
    $homestayPath = route('conservation-reports.index', 'homestay', false);
    $homestay = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
        ->get('http://cds-system.test'.$homestayPath.'?__cds_perf=1')
        ->assertOk();

    expect($history->headers->get('X-CDS-Perf-Route'))->toBe('submission-tracking.index')
        ->and($history->headers->get('X-CDS-Perf-Context'))->toContain('view=history')
        ->and($history->headers->has('Server-Timing'))->toBeTrue()
        ->and($homestay->headers->get('X-CDS-Perf-Route'))->toBe('conservation-reports.index')
        ->and($homestay->headers->has('Server-Timing'))->toBeTrue();
});

test('CENRO focal Homestay navigation profiles its paginated data path at twelve and sixty rows', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    $actor = User::factory()->create([
        'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati',
    ]);
    $actor->assignRole(Role::findOrCreate('no_role', 'web'));
    foreach (['submission-tracking.view', 'technical-reports.view', 'technical-reports.update'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);

    $profiles = [];
    foreach ([12, 60] as $size) {
        $area = ProtectedArea::create([
            'name' => 'Homestay Navigation '.$size, 'short_name' => 'HST'.$size, 'category' => 'Protected Landscape',
            'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        ProtectedAreaOfficeAssignment::create([
            'protected_area_id' => $area->id,
            'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
            'assignment_type' => 'supervising',
        ]);
        foreach (range(1, $size) as $index) {
            ConservationReportSubmission::query()->create([
                'workflow_key' => 'homestay', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
                'activity_name' => 'Homestay navigation '.$size.' '.$index, 'document_type' => 'Progress Report',
                'reporting_period' => 'Quarter 1', 'date_accomplished' => '2026-03-02',
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
        }

        $samples = [];
        for ($sample = 0; $sample < 5; $sample++) {
            $started = hrtime(true);
            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test'.route('conservation-reports.index', 'homestay', false).'?protected_area_id='.$area->id.'&__cds_perf=1')
                ->assertOk();
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
            $props = $response->inertiaProps();
            expect(data_get($props, 'submissions.data'))->toHaveCount(10)
                ->and(data_get($props, 'submissions.total'))->toBe($size)
                ->and($response->headers->get('X-CDS-Perf-Route'))->toBe('conservation-reports.index');
            preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', (string) $response->headers->get('Server-Timing'), $sql);
            $samples[] = [
                'elapsed_ms' => round($elapsedMs, 2),
                'sql_queries' => (int) ($sql[2] ?? 0),
                'sql_ms' => (float) ($sql[1] ?? 0),
                'response_bytes' => strlen((string) $response->getContent()),
                'rows_returned' => count(data_get($props, 'submissions.data', [])),
            ];
        }
        $profiles[$size] = $samples;
    }

    if (getenv('CDS_NAV_PROFILE_OUTPUT') === '1') {
        fwrite(STDERR, 'HOMESTAY_NAVIGATION_PROFILE actor=CENRO_CDS_FOCAL fixed_clock=true samples_per_size=5 page_size=10 '.json_encode($profiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('populated terminal history retains canonical membership and profiles at twelve and sixty authorized rows', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    Storage::fake('local');
    Notification::fake();
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);

    $makeActor = function (string $category, string $office): User {
        $actor = User::factory()->create([
            'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
            'section' => $category, 'office_designated' => $office,
        ]);
        $actor->assignRole(Role::findOrCreate('no_role', 'web'));
        foreach (['reports.view', 'bms.update'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    };

    $focal = $makeActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    foreach ([
        [OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'],
        [OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'],
        [OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'],
        [OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'],
        [OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'],
        [OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'],
        [OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental'],
    ] as [$category, $office]) {
        $makeActor($category, $office);
    }

    $area = ProtectedArea::create([
        'name' => 'Populated History Navigation PA', 'short_name' => 'PHNP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    $records = [];
    for ($index = 1; $index <= 12; $index++) {
        $records[$index] = BmsReportSubmission::query()->create([
            'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
            'activity_name' => 'History navigation BMS '.$index, 'document_type' => 'Report',
            'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
            'created_by' => $focal->id, 'updated_by' => $focal->id,
        ]);
    }

    $terminal = $records[1];
    $officialPath = 'isolated/history-terminal-'.$terminal->id.'.pdf';
    Storage::disk('local')->put($officialPath, "%PDF-1.4\nIsolated terminal history fixture");
    $terminal->update(['mov_file_path' => $officialPath, 'mov_file_name' => 'Terminal history fixture.pdf']);
    $routing = app(DocumentRoutingTransitionService::class);
    $actorsByAction = [
        'forward_to_cenro_chief' => $focal,
        'receive_at_cenro_chief' => User::query()->where('section', OrganizationalAccessService::CENRO_CHIEF)->firstOrFail(),
        'forward_to_cenro_records' => User::query()->where('section', OrganizationalAccessService::CENRO_CHIEF)->firstOrFail(),
        'receive_at_cenro_records' => User::query()->where('section', OrganizationalAccessService::CENRO_RECORDS)->firstOrFail(),
        'forward_to_penro_records' => User::query()->where('section', OrganizationalAccessService::CENRO_RECORDS)->firstOrFail(),
        'receive_at_penro_records' => User::query()->where('section', OrganizationalAccessService::PENRO_RECORDS)->firstOrFail(),
        'forward_to_office_penro' => User::query()->where('section', OrganizationalAccessService::PENRO_RECORDS)->firstOrFail(),
        'receive_at_office_penro' => User::query()->where('section', OrganizationalAccessService::OFFICE_PENRO)->firstOrFail(),
        'assign_to_tsd_chief' => User::query()->where('section', OrganizationalAccessService::OFFICE_PENRO)->firstOrFail(),
        'receive_at_tsd_chief' => User::query()->where('section', OrganizationalAccessService::PENRO_TSD_CHIEF)->firstOrFail(),
        'forward_to_cds_focal' => User::query()->where('section', OrganizationalAccessService::PENRO_TSD_CHIEF)->firstOrFail(),
        'receive_at_cds_focal' => User::query()->where('section', OrganizationalAccessService::PENRO_FOCAL)->firstOrFail(),
        'forward_to_cds_chief' => User::query()->where('section', OrganizationalAccessService::PENRO_FOCAL)->firstOrFail(),
        'receive_at_cds_chief' => User::query()->where('section', OrganizationalAccessService::PENRO_CHIEF)->firstOrFail(),
        'recommend_to_office_penro' => User::query()->where('section', OrganizationalAccessService::PENRO_CHIEF)->firstOrFail(),
        'receive_at_office_penro_final' => User::query()->where('section', OrganizationalAccessService::OFFICE_PENRO)->firstOrFail(),
        'approve_for_regional_release' => User::query()->where('section', OrganizationalAccessService::OFFICE_PENRO)->firstOrFail(),
        'receive_at_penro_records_final' => User::query()->where('section', OrganizationalAccessService::PENRO_RECORDS)->firstOrFail(),
        'release_to_regional' => User::query()->where('section', OrganizationalAccessService::PENRO_RECORDS)->firstOrFail(),
    ];
    foreach ($actorsByAction as $action => $actor) {
        $routing->transition($terminal->fresh(), 'bms', $action, $actor->id);
    }
    $routing->transition($records[2]->fresh(), 'bms', 'forward_to_cenro_chief', $focal->id);
    expect($routing->state($terminal->fresh(), 'bms')['stage'])->toBe('released_to_regional')
        ->and(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $terminal->id)->count())->toBe(19);

    $this->actingAs($focal);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);
    $sizes = [12, 60];
    $profile = [];
    $nextRecord = 13;

    foreach ($sizes as $size) {
        while (count($records) < $size) {
            $records[$nextRecord] = BmsReportSubmission::query()->create([
                'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
                'activity_name' => 'History navigation BMS '.$nextRecord, 'document_type' => 'Report',
                'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
                'created_by' => $focal->id, 'updated_by' => $focal->id,
            ]);
            $nextRecord++;
        }

        $samples = [];
        for ($sample = 0; $sample < 5; $sample++) {
            app('router')->getRoutes()->getByName('submission-tracking.index')?->flushController();
            $started = hrtime(true);
            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test/submission-tracking?view=history&protected_area_id='.$area->id.'&__cds_perf=1')
                ->assertOk();
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
            $props = $response->inertiaProps();
            $queues = $props['workspaceQueues'];
            $historyKeys = collect($queues['history'])->map(fn (array $row): string => $row['source'].':'.$row['source_id']);
            $incomingKeys = collect($queues['incoming'])->map(fn (array $row): string => $row['source'].':'.$row['source_id']);
            $outgoingKeys = collect($queues['outgoing'])->map(fn (array $row): string => $row['source'].':'.$row['source_id']);
            $allKeys = $historyKeys->concat($incomingKeys)->concat($outgoingKeys);
            $expectedKeys = collect($records)->take($size)->map(fn (BmsReportSubmission $record): string => 'bms:'.$record->id)->sort()->values();
            expect($response->headers->get('X-CDS-Perf-Actor-Category'))->toBe(OrganizationalAccessService::CENRO_FOCAL)
                ->and($allKeys->count())->toBe($size)
                ->and($allKeys->unique()->sort()->values())->toEqual($expectedKeys)
                ->and($historyKeys->all())->toBe(['bms:'.$terminal->id])
                ->and($incomingKeys)->not->toContain('bms:'.$terminal->id, 'bms:'.$records[2]->id)
                ->and($outgoingKeys)->toContain('bms:'.$records[2]->id);

            $timing = (string) $response->headers->get('Server-Timing');
            preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', $timing, $sql);
            preg_match('/(?:^|, )st_workspace_queues;dur=([0-9.]+)/', $timing, $workspace);
            preg_match('/(?:^|, )st_routing_presentation;dur=([0-9.]+)/', $timing, $presentation);
            preg_match('/(?:^|, )st_history_queue;dur=([0-9.]+)/', $timing, $historyPhase);
            $samples[] = [
                'elapsed_ms' => round($elapsedMs, 2),
                'sql_queries' => (int) ($sql[2] ?? 0),
                'sql_ms' => (float) ($sql[1] ?? 0),
                'workspace_rows' => $allKeys->unique()->count(),
                'history_rows' => $historyKeys->count(),
                'workspace_queues_ms' => (float) ($workspace[1] ?? 0),
                'routing_presentation_ms' => (float) ($presentation[1] ?? 0),
                'history_filter_ms' => (float) ($historyPhase[1] ?? 0),
            ];
        }
        $profile[$size] = $samples;
    }

    $selected = $this->get(route('submission-tracking.index', [
        'view' => 'history', 'protected_area_id' => $area->id, 'source' => 'bms', 'source_id' => $records[2]->id,
    ]))->assertOk()->inertiaProps();
    expect($selected['trackingContext']['selected_record']['source'])->toBe('bms')
        ->and($selected['trackingContext']['selected_record']['source_id'])->toBe($records[2]->id)
        ->and(collect($selected['workspaceQueues']['history'])->pluck('source_id'))->toContain($terminal->id);

    if (getenv('CDS_NAV_PROFILE_OUTPUT') === '1') {
        fwrite(STDERR, 'CENRO_FOCAL_POPULATED_HISTORY_PROFILE actor=CENRO_CDS_FOCAL fixed_clock=true terminal_records=1 intermediate_records=1 archive_storage_notifications=fake rows=12_and_60 samples_per_size=5 '.json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('isolated Submission Tracking navigation trace samples synthetic one ten and sixty row scopes', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    $actor = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);

    $results = [];
    foreach ([1, 10, 60] as $size) {
        $area = ProtectedArea::create([
            'name' => 'Navigation Trace PA '.$size, 'short_name' => 'NTPA'.$size, 'category' => 'Protected Landscape',
            'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        for ($index = 1; $index <= $size; $index++) {
            trackingPerformanceReport($area, $actor, $size * 100 + $index);
        }

        $samples = [];
        for ($sample = 1; $sample <= 5; $sample++) {
            $started = hrtime(true);
            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test/submission-tracking?view=outgoing&protected_area_id='.$area->id.'&__cds_perf=1')
                ->assertOk();
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
            $rows = $response->inertiaProps()['workspaceQueues']['incoming'];
            $timing = (string) $response->headers->get('Server-Timing');
            preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', $timing, $sqlMatch);
            preg_match('/st_routing_presentation;dur=([0-9.]+)/', $timing, $routingMatch);
            preg_match('/st_queue_projection;dur=([0-9.]+)/', $timing, $queueMatch);
            $presentationPhases = [];
            foreach (['st_normalize_base', 'st_pamb_timeline', 'st_pamb_action_projection', 'st_mov_presentation', 'st_mov_pending_days', 'st_mov_turnaround', 'st_document_routing', 'st_routing_tail'] as $phase) {
                preg_match('/'.$phase.';dur=([0-9.]+)/', $timing, $phaseMatch);
                $presentationPhases[$phase.'_ms'] = (float) ($phaseMatch[1] ?? 0);
            }
            expect($rows)->toHaveCount($size);
            $samples[] = [
                'elapsed_ms' => round($elapsedMs, 2),
                'sql_queries' => (int) ($sqlMatch[2] ?? 0),
                'sql_ms' => round((float) ($sqlMatch[1] ?? 0), 2),
                'routing_presentation_ms' => (float) ($routingMatch[1] ?? 0),
                ...$presentationPhases,
                'queue_projection_ms' => (float) ($queueMatch[1] ?? 0),
            ];
        }
        $results[$size] = $samples;
    }

    fwrite(STDERR, '[CDS_NAV_SQLITE_SYNTHETIC] actor=GLOBAL_OR_OTHER route=submission-tracking.index view=outgoing samples_per_size=5 first_sample=cold_in_process remaining_samples=warm_in_process '.json_encode($results, JSON_UNESCAPED_SLASHES).PHP_EOL);
});

test('date-only trend projection preserves PA scope for CENRO and Super Admin', function (): void {
    $areaOwner = User::factory()->create(['section' => 'CDS']);
    $area = ProtectedArea::create([
        'name' => 'Pujada Bay Protected Landscape and Seascape', 'short_name' => 'PBPLS', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $areaOwner->id, 'updated_by' => $areaOwner->id,
    ]);
    trackingPerformanceReport($area, $areaOwner, 1)->update(['date_received_penro' => '2026-03-05']);

    $global = User::factory()->create(['section' => 'CDS', 'is_approved' => true, 'is_active' => true]);
    $global->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $cenro = User::factory()->create([
        'section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => 'conservation', 'office_designated' => 'CENRO Mati',
        'is_approved' => true, 'is_active' => true,
    ]);
    $cenroReport = trackingPerformanceReport($area, $areaOwner, 2);
    $cenroReport->update(['date_received_penro' => '2026-03-06']);

    $tracking = app(SubmissionTrackingService::class);
    $this->actingAs($global);
    $globalDates = collect(iterator_to_array($tracking->receivedDateValues(), false))
        ->map(fn ($date): ?string => \App\Support\DatePresentationNormalizer::toDateString($date))->filter()->all();
    $this->actingAs($cenro);
    $cenroDates = collect(iterator_to_array($tracking->receivedDateValues(), false))
        ->map(fn ($date): ?string => \App\Support\DatePresentationNormalizer::toDateString($date))->filter()->all();

    expect($globalDates)->toContain('2026-03-05', '2026-03-06')
        ->and($cenroDates)->toContain('2026-03-05', '2026-03-06');
});

test('CENRO workspace protected-area authorization queries stay bounded for repeated PA rows', function (): void {
    $actor = User::factory()->create([
        'section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => 'conservation', 'office_designated' => 'CENRO Mati',
        'is_approved' => true, 'is_active' => true,
    ]);
    $area = ProtectedArea::create([
        'name' => 'Pujada Bay Protected Landscape and Seascape', 'short_name' => 'PBPLS', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    trackingPerformanceReport($area, $actor, 1);

    $assignmentQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$assignmentQueries): void {
        if (str_contains(strtolower($query->sql), 'protected_area_office_assignments')) {
            $assignmentQueries++;
    }
});



    $tracking = app(SubmissionTrackingService::class);
    $this->actingAs($actor);
    expect($tracking->records([], null, false))->toHaveCount(1);
    $singleRowQueryCount = $assignmentQueries;

    for ($index = 2; $index <= 20; $index++) trackingPerformanceReport($area, $actor, $index);
    $assignmentQueries = 0;
    $largeRows = $tracking->records([], null, false);
    expect($largeRows)->toHaveCount(20)
        ->and($assignmentQueries)->toBeLessThanOrEqual($singleRowQueryCount)
        ->and($assignmentQueries)->toBeLessThanOrEqual(26);
});

test('CENRO chief PAMB attachment projection reuses only the row routing state and preserves every stage flag', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    $actor = User::factory()->create([
        'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Mati',
    ]);
    $actor->assignRole(Role::findOrCreate('no_role', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Chief Projection Profile PA', 'short_name' => 'CPPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $officeId = OrganizationalOffice::query()->where('name', 'CENRO Mati')->value('id');
    expect($officeId)->not->toBeNull();
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id, 'organizational_office_id' => $officeId,
        'assignment_type' => 'supervising',
    ]);

    $pamb = collect();
    for ($index = 1; $index <= 5; $index++) {
        $report = trackingPerformanceReport($area, $actor, 12000 + $index);
        if ($index <= 4) {
            $report->update([
                'mov_file_path' => 'isolated/trace-'.$index.'.pdf',
                'mov_processing_status' => match ($index) {
                    2 => 'submitted_for_review',
                    3 => 'needs_correction',
                    4 => 'ready_for_release',
                    default => null,
                },
            ]);
        }
        if ($index === 2) {
            DocumentRoutingEvent::query()->create([
                'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => 'regular_pamb',
                'event_key' => 'forwarded', 'from_stage' => 'cenro_preparation', 'to_stage' => 'transit_to_cenro_chief',
                'occurred_at' => now(), 'recorded_by' => $actor->id,
                'metadata' => ['action_key' => 'forward_to_cenro_chief'],
            ]);
        } elseif ($index === 3) {
            DocumentRoutingEvent::query()->create([
                'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => 'regular_pamb',
                'event_key' => 'returned_for_correction', 'from_stage' => 'transit_to_cenro_records', 'to_stage' => 'cenro_chief',
                'occurred_at' => now(), 'recorded_by' => $actor->id,
                'metadata' => ['action_key' => 'return_for_correction_cenro_records', 'correction_reason_key' => 'missing_detail'],
            ]);
        } elseif ($index === 4) {
            DocumentRoutingEvent::query()->create([
                'source_type' => 'conservation', 'source_id' => $report->id, 'workflow_key' => 'regular_pamb',
                'event_key' => 'released', 'from_stage' => 'penro_records_final', 'to_stage' => 'released_to_regional',
                'occurred_at' => now(), 'recorded_by' => $actor->id,
                'metadata' => ['action_key' => 'release_to_regional'],
            ]);
        }
        $pamb->push($report);
    }
    $bms = collect();
    for ($index = 1; $index <= 7; $index++) {
        $bms->push(BmsReportSubmission::create([
            'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
            'activity_name' => 'Chief projection BMS '.$index, 'document_type' => 'Report',
            'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]));
    }

    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);
    $profiles = [];
    $referenceAttachmentMs = [];
    $tracking = app(SubmissionTrackingService::class);
    $referenceCases = $pamb->map(function (ConservationReportSubmission $report): array {
        $stored = $report->fresh()->load([
            'protectedArea.supervisingOfficeAssignment.office',
            'routingEvents.recordedBy',
            'movReviewEvents.recordedBy',
            'movReviewedBy:id,name',
        ]);
        $events = DocumentRoutingEvent::query()
            ->where('source_type', 'conservation')
            ->where('source_id', $stored->id)
            ->with('recordedBy:id,name,section,office_designated')
            ->orderBy('occurred_at')->orderBy('id')->get();
        return [$stored, $events];
    });

    for ($sample = 0; $sample < 5; $sample++) {
        $started = hrtime(true);
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
            ->get('http://cds-system.test/submission-tracking?view=outgoing&__cds_perf=1')
            ->assertOk();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $props = $response->inertiaProps();
        $rows = $tracking->records([], null, false)->keyBy(fn (array $row): string => $row['source'].':'.$row['source_id']);
        expect($rows)->toHaveCount(12);

        foreach ($rows as $row) {
            $source = (string) $row['source'];
            $sourceId = (int) $row['source_id'];
            $sourceConfig = $tracking->source($source);
            $storedRecord = $sourceConfig['model']::query()->findOrFail($sourceId);
            $freshEvents = DocumentRoutingEvent::query()
                ->where('source_type', $source)
                ->where('source_id', $sourceId)
                ->orderBy('occurred_at')->orderBy('id')->get();
            $stage = (string) data_get($row, 'routing.current_stage', $row['stage'] ?? '');

            expect(data_get($row, 'routing.attachment_allowed'))
                ->toBe($tracking->canAttachRoutingCopy($source, $storedRecord, $stage, $freshEvents));
        }

        $legacyStarted = hrtime(true);
        foreach ($referenceCases as [$stored, $events]) {
            $report = $stored;
            $row = $rows->get('conservation:'.$report->id);
            foreach ($row['routing_timeline'] as $stage) {
                $expected = $tracking->canAttachRoutingCopy('conservation', $stored, (string) ($stage['stage_key'] ?? $stage['key']), $events);
                if ($stage['attachment_allowed'] !== $expected) {
                    throw new RuntimeException(json_encode([
                        'record' => $report->id,
                        'stage' => $stage['stage_key'] ?? $stage['key'],
                        'projected' => $stage['attachment_allowed'],
                        'expected' => $expected,
                    ], JSON_THROW_ON_ERROR));
                }
            }
        }
        $referenceAttachmentMs[] = (hrtime(true) - $legacyStarted) / 1_000_000;

        $timing = (string) $response->headers->get('Server-Timing');
        preg_match('/st_pamb_attachment_projection;dur=([0-9.]+)/', $timing, $attachmentMatch);
        preg_match('/st_pamb_action_projection;dur=([0-9.]+)/', $timing, $actionMatch);
        preg_match('/st_document_routing;dur=([0-9.]+)/', $timing, $routingMatch);
        preg_match('/(?:^|, )app_pipeline;dur=([0-9.]+)/', $timing, $pipelineMatch);
        preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', $timing, $sqlMatch);
        $counts = (string) $response->headers->get('X-CDS-Perf-Counts');
        expect($response->headers->get('X-CDS-Perf-Actor-Category'))->toBe(OrganizationalAccessService::CENRO_CHIEF)
            ->and($counts)->toContain('projected_rows=12')
            ->and($counts)->toContain('canonical_routing_presentations=12')
            ->and($counts)->toContain('mov_presentations=5')
            ->and($counts)->toContain('canonical_state_reuses=12');
        $profiles[] = [
            'elapsed_ms' => round($elapsedMs, 2),
            'app_pipeline_ms' => (float) ($pipelineMatch[1] ?? 0),
            'pamb_action_projection_ms' => (float) ($actionMatch[1] ?? 0),
            'document_routing_ms' => (float) ($routingMatch[1] ?? 0),
            'attachment_projection_ms' => (float) ($attachmentMatch[1] ?? 0),
            'reference_recompute_ms' => round($referenceAttachmentMs[array_key_last($referenceAttachmentMs)], 2),
            'sql_queries' => (int) ($sqlMatch[2] ?? 0),
            'sql_ms' => (float) ($sqlMatch[1] ?? 0),
            'canonical_state_builds' => (int) (preg_match('/(?:^|;)canonical_state_builds=([0-9]+)/', $counts, $builds) ? $builds[1] : 0),
            'canonical_state_reuses' => (int) (preg_match('/(?:^|;)canonical_state_reuses=([0-9]+)/', $counts, $reuses) ? $reuses[1] : 0),
        ];
    }

    if (getenv('CDS_NAV_PROFILE_OUTPUT') === '1') {
        fwrite(STDERR, "CENRO_CHIEF_PROFILE ".json_encode($profiles, JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('CENRO focal projection keeps actor-scoped routing and equivalent attachment decisions', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    $actor = User::factory()->create([
        'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati',
    ]);
    $actor->assignRole(Role::findOrCreate('no_role', 'web'));
    foreach (['submission-tracking.view', 'technical-reports.view', 'technical-reports.update', 'bms.update'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $area = ProtectedArea::create([
        'name' => 'Focal Projection Profile PA', 'short_name' => 'FPPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $officeId = OrganizationalOffice::query()->where('name', 'CENRO Mati')->value('id');
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id, 'organizational_office_id' => $officeId,
        'assignment_type' => 'supervising',
    ]);

    $focalFirstHandoffId = null;
    for ($index = 1; $index <= 5; $index++) {
        $report = trackingPerformanceReport($area, $actor, 13000 + $index);
        if ($index === 2) $focalFirstHandoffId = (int) $report->getKey();
        if ($index > 1) {
            $report->update([
                'mov_file_path' => 'isolated/focal-'.$index.'.pdf',
                'mov_processing_status' => match ($index) {
                    2 => 'submitted_for_review',
                    3 => 'needs_correction',
                    4 => 'ready_for_release',
                    default => 'activity_conducted',
                },
            ]);
        }
    }
    for ($index = 1; $index <= 7; $index++) {
        $bms = BmsReportSubmission::create([
            'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
            'activity_name' => 'Focal projection BMS '.$index, 'document_type' => 'Report',
            'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        if ($index === 7) {
            // Retain the legacy attachment predicate when a canonical event
            // and compatibility milestone disagree on a non-Conservation source.
            DocumentRoutingEvent::query()->create([
                'source_type' => 'bms', 'source_id' => $bms->id, 'workflow_key' => 'bms',
                'event_key' => 'released', 'from_stage' => 'penro_records_final', 'to_stage' => 'released_to_regional',
                'occurred_at' => now(), 'recorded_by' => $actor->id,
                'metadata' => ['action_key' => 'release_to_regional'],
            ]);
        }
    }

    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);
    $calendarProfiles = [];
    app()->bind(BusinessCalendarService::class, function () use (&$calendarProfiles): CountingSubmissionCalendar {
        $profile = new CountingSubmissionCalendar();
        $calendarProfiles[] = $profile;
        return $profile;
    });
    $tracking = app(SubmissionTrackingService::class);
    $samples = [];
    for ($sample = 0; $sample < 5; $sample++) {
        app('router')->getRoutes()->getByName('submission-tracking.index')?->flushController();
        $calendarProfiles = [];
        $started = hrtime(true);
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
            ->get('http://cds-system.test/submission-tracking?view=outgoing&__cds_perf=1')
            ->assertOk();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $calendarWork = CountingSubmissionCalendar::aggregate($calendarProfiles);
        $props = $response->inertiaProps();
        $firstHandoff = collect($props['workspaceQueues']['incoming'])->first(fn (array $row): bool => ($row['source'] ?? null) === 'conservation' && (int) ($row['source_id'] ?? 0) === $focalFirstHandoffId);
        expect($firstHandoff)->not->toBeNull()
            ->and($firstHandoff['can_transition'])->toBeTrue()
            ->and(collect($firstHandoff['routing']['actions'])->pluck('key'))->toContain('forward_to_cenro_chief');
        $rows = $tracking->records([], null, false)->keyBy(fn (array $row): string => $row['source'].':'.$row['source_id']);
        expect($rows)->toHaveCount(12);
        $legacyMilestoneMismatch = $rows->get('bms:'.$bms->id);
        expect($legacyMilestoneMismatch['routing_complete'])->toBeTrue()
            ->and($legacyMilestoneMismatch['routing']['attachment_allowed'])->toBeTrue();

        foreach ($rows as $row) {
            $source = (string) $row['source'];
            $sourceId = (int) $row['source_id'];
            $record = $tracking->source($source)['model']::query()->findOrFail($sourceId);
            $events = DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $sourceId)
                ->orderBy('occurred_at')->orderBy('id')->get();
            $stage = (string) data_get($row, 'routing.current_stage', $row['stage'] ?? '');
            expect(data_get($row, 'routing.attachment_allowed'))
                ->toBe($tracking->canAttachRoutingCopy($source, $record, $stage, $events));
        }

        $counts = (string) $response->headers->get('X-CDS-Perf-Counts');
        expect($response->headers->get('X-CDS-Perf-Actor-Category'))->toBe(OrganizationalAccessService::CENRO_FOCAL)
            ->and($counts)->toContain('projected_rows=12')
            ->and($counts)->toContain('canonical_routing_presentations=12')
            ->and($counts)->toContain('mov_presentations=5')
            ->and($counts)->toContain('canonical_state_builds=12')
            ->and($counts)->toContain('canonical_state_reuses=12');
        $timing = (string) $response->headers->get('Server-Timing');
        preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', $timing, $sql);
        preg_match('/st_document_routing;dur=([0-9.]+)/', $timing, $documentRouting);
        preg_match('/st_routing_presentation;dur=([0-9.]+)/', $timing, $routingPresentation);
        $samples[] = [
            'elapsed_ms' => round($elapsedMs, 2),
            'sql_queries' => (int) ($sql[2] ?? 0),
            'sql_ms' => (float) ($sql[1] ?? 0),
            'document_routing_ms' => (float) ($documentRouting[1] ?? 0),
            'routing_presentation_ms' => (float) ($routingPresentation[1] ?? 0),
            'calendar_calculation' => $calendarWork,
        ];
    }

    if (getenv('CDS_NAV_PROFILE_OUTPUT') === '1') {
        fwrite(STDERR, 'CENRO_FOCAL_PROFILE actor=CENRO_CDS_FOCAL rows=12 PAMB=5 BMS=7 fixed_clock=true samples=5 '.json_encode($samples, JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('dense routed history profiles preserve event-linked attachments across source ID collisions', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    Storage::fake('local');
    $chief = User::factory()->create([
        'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Mati',
    ]);
    $chief->assignRole(Role::findOrCreate('no_role', 'web'));
    foreach (['submission-tracking.view', 'technical-reports.view', 'technical-reports.update', 'bms.update'] as $permission) {
        $chief->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $focal = User::factory()->create([
        'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati',
    ]);
    $area = ProtectedArea::create([
        'name' => 'Dense History Profile PA', 'short_name' => 'DHPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    $eventSequence = 0;
    $recordAttachment = function (string $source, int $sourceId, string $eventKey, string $from, string $to, User $recordedBy, string $action, string $purpose = 'routing_copy') use (&$eventSequence): DocumentRoutingEvent {
        $eventSequence++;
        $event = DocumentRoutingEvent::query()->create([
            'source_type' => $source, 'source_id' => $sourceId,
            'workflow_key' => $source === 'conservation' ? 'regular_pamb' : 'bms',
            'event_key' => $eventKey, 'from_stage' => $from, 'to_stage' => $to,
            'from_office' => $eventKey === 'returned_for_correction' ? 'CENRO CDS Chief' : 'CENRO CDS Focal Person',
            'to_office' => $eventKey === 'returned_for_correction' ? 'CENRO CDS Focal Person' : 'CENRO CDS Chief',
            'occurred_at' => now()->addSeconds($eventSequence), 'recorded_by' => $recordedBy->id,
            'metadata' => ['action_key' => $action, ...($eventKey === 'returned_for_correction' ? ['correction_cycle' => 1, 'correction_reason_key' => 'missing_detail'] : [])],
        ]);
        $path = 'benchmark-routing/'.$source.'-'.$sourceId.'-'.$event->id.'.pdf';
        Storage::disk('local')->put($path, 'isolated fixture attachment');
        SubmissionRoutingAttachment::query()->create([
            'source' => $source, 'source_id' => $sourceId,
            'document_routing_event_id' => $event->id,
            'stage_key' => $to, 'action_key' => $action, 'purpose' => $purpose,
            'original_name' => 'supporting-event-'.$event->id.'.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => 27, 'uploaded_by' => $recordedBy->id,
        ]);

        return $event;
    };

    $createdPamb = 0;
    $createdBms = 0;
    $profileSizes = [12 => ['pamb' => 5, 'bms' => 7], 60 => ['pamb' => 25, 'bms' => 35]];
    $profileResults = [];
    $this->actingAs($chief);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);

    foreach ($profileSizes as $size => $mix) {
        while ($createdPamb < $mix['pamb']) {
            $createdPamb++;
            $report = trackingPerformanceReport($area, $focal, 30000 + $createdPamb);
            $report->update([
                'mov_file_path' => 'isolated/dense-pamb-'.$createdPamb.'.pdf',
                'mov_processing_status' => $createdPamb === 2 ? 'needs_correction' : 'submitted_for_review',
            ]);
            $recordAttachment('conservation', (int) $report->id, 'forwarded', 'preparation', 'transit_to_cenro_chief', $focal, 'forward_to_cenro_chief');
            if ($createdPamb === 2) {
                $recordAttachment('conservation', (int) $report->id, 'received', 'transit_to_cenro_chief', 'cenro_chief', $chief, 'receive_at_cenro_chief');
                $recordAttachment('conservation', (int) $report->id, 'returned_for_correction', 'cenro_chief', 'preparation', $chief, 'return_to_cenro_focal', 'correction_reference');
            }
        }
        while ($createdBms < $mix['bms']) {
            $createdBms++;
            $report = BmsReportSubmission::query()->create([
                'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
                'activity_name' => 'Dense history BMS '.$createdBms, 'document_type' => 'Report',
                'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
                'created_by' => $focal->id, 'updated_by' => $focal->id,
            ]);
            $recordAttachment('bms', (int) $report->id, 'forwarded', 'preparation', 'transit_to_cenro_chief', $focal, 'forward_to_cenro_chief');
        }

        $samples = [];
        for ($sample = 0; $sample < 5; $sample++) {
            $started = hrtime(true);
            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test/submission-tracking?view=outgoing&protected_area_id='.$area->id.'&__cds_perf=1')
                ->assertOk();
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
            $props = $response->inertiaProps();
            $workspaceRows = collect($props['workspaceQueues'])->flatten(1)->unique(fn (array $row): string => $row['source'].':'.$row['source_id'])->values();
            expect($workspaceRows)->toHaveCount($size)
                ->and($response->headers->get('X-CDS-Perf-Actor-Category'))->toBe(OrganizationalAccessService::CENRO_CHIEF);

            foreach ($workspaceRows as $row) {
                foreach ($row['routing']['routing_history'] ?? [] as $historyEvent) {
                    $attachment = $historyEvent['attachment'] ?? null;
                    expect($attachment)->toBeArray()
                        ->and($attachment['name'])->toBe('supporting-event-'.$historyEvent['id'].'.pdf')
                        ->and($attachment['is_routing_copy'])->toBeTrue()
                        ->and($attachment['version_source'])->toBe('routing')
                        ->and($attachment['url'])->toContain('/'.$row['source'].'/'.$row['source_id'].'/');
                }
            }

            $timing = (string) $response->headers->get('Server-Timing');
            $phases = [];
            foreach (['to_trace_start', 'app_pipeline', 'controller_action', 'inertia_share_eager', 'shared_notification_bell', 'st_pagination', 'st_source_load', 'st_workspace_queues', 'st_routing_presentation', 'st_normalize_base', 'st_pamb_action_projection', 'st_mov_presentation', 'st_document_routing', 'st_document_attachment_lookup', 'st_routing_tail', 'st_pamb_attachment_projection', 'st_queue_projection', 'st_history_queue', 'st_incoming_queue', 'st_outgoing_queue', 'st_filter_options', 'st_context', 'st_response_build'] as $phase) {
                preg_match('/(?:^|, )'.preg_quote($phase, '/').';dur=([0-9.]+)(?:;desc="queries ([0-9]+); sql_ms ([0-9.]+)")?/', $timing, $phaseMatch);
                if (isset($phaseMatch[1])) {
                    $phases[$phase] = [
                        'ms' => (float) $phaseMatch[1],
                        'queries' => (int) ($phaseMatch[2] ?? 0),
                        'sql_ms' => (float) ($phaseMatch[3] ?? 0),
                    ];
                }
            }
            preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', $timing, $sqlMatch);
            $untracedStarted = hrtime(true);
            $untraced = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test/submission-tracking?view=outgoing&protected_area_id='.$area->id)
                ->assertOk();
            $untracedElapsedMs = (hrtime(true) - $untracedStarted) / 1_000_000;
            expect($untraced->headers->has('Server-Timing'))->toBeFalse()
                ->and($untraced->headers->has('X-CDS-Perf-Id'))->toBeFalse()
                ->and($untraced->inertiaProps()['workspaceQueues'])->toEqual($props['workspaceQueues']);
            $samples[] = [
                'elapsed_ms' => round($elapsedMs, 2),
                'untraced_elapsed_ms' => round($untracedElapsedMs, 2),
                'response_bytes' => strlen((string) $response->getContent()),
                'untraced_response_bytes' => strlen((string) $untraced->getContent()),
                'sql_queries' => (int) ($sqlMatch[2] ?? 0),
                'sql_ms' => (float) ($sqlMatch[1] ?? 0),
                'workspace_rows' => $workspaceRows->count(),
                'phases_ms' => $phases,
            ];
        }
        $profileResults[$size] = $samples;
    }

    if (getenv('CDS_NAV_PROFILE_OUTPUT') === '1') {
        fwrite(STDERR, 'DENSE_ROUTING_ATTACHMENT_PROFILE actor=CENRO_CDS_CHIEF fixed_clock=true source_mix=5_of_12_and_25_of_60_PAMB remaining_BMS samples_per_size=5 '.json_encode($profileResults, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('CENRO chief query families compare the same PAMB and BMS mix at one ten and sixty rows', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Manila'));
    $actor = User::factory()->create([
        'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Mati',
    ]);
    $actor->assignRole(Role::findOrCreate('no_role', 'web'));
    foreach (['submission-tracking.view', 'technical-reports.view', 'technical-reports.update'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $area = ProtectedArea::create([
        'name' => 'Query Family Growth PA', 'short_name' => 'QFGPA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $officeId = OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id');
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id, 'organizational_office_id' => $officeId,
        'assignment_type' => 'supervising',
    ]);

    $this->actingAs($actor);
    $this->app['env'] = 'local';
    config(['app.env' => 'local']);
    $tracking = app(SubmissionTrackingService::class);
    $samplesBySize = [];
    $existingPamb = 0;
    $existingBms = 0;
    foreach ([1, 10, 60] as $size) {
        $wantedPamb = max(1, (int) round($size * 5 / 12));
        $wantedBms = $size - $wantedPamb;
        while ($existingPamb < $wantedPamb) {
            $existingPamb++;
            trackingPerformanceReport($area, $actor, 20000 + $existingPamb);
        }
        while ($existingBms < $wantedBms) {
            $existingBms++;
            BmsReportSubmission::create([
                'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
                'activity_name' => 'Query family BMS '.$existingBms, 'document_type' => 'Report',
                'semester' => '1st Semester', 'date_accomplished' => '2026-03-02',
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
        }

        $samples = [];
        for ($sample = 0; $sample < 5; $sample++) {
            $started = hrtime(true);
            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test/submission-tracking?view=outgoing&protected_area_id='.$area->id.'&__cds_perf=1')
                ->assertOk();
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
            $timing = (string) $response->headers->get('Server-Timing');
            $metrics = [];
            foreach (['app_pipeline', 'st_pagination', 'st_source_load', 'st_filter_options', 'st_workspace_queues', 'st_pamb_action_projection', 'st_document_routing', 'st_document_attachment_lookup', 'st_pamb_attachment_projection'] as $phase) {
                preg_match('/(?:^|, )'.preg_quote($phase, '/').';dur=([0-9.]+)(?:;desc="queries ([0-9]+); sql_ms ([0-9.]+)")?/', $timing, $phaseMatch);
                $metrics[$phase] = [
                    'ms' => (float) ($phaseMatch[1] ?? 0),
                    'queries' => isset($phaseMatch[2]) ? (int) $phaseMatch[2] : 0,
                    'sql_ms' => (float) ($phaseMatch[3] ?? 0),
                ];
            }
            $counts = (string) $response->headers->get('X-CDS-Perf-Counts');
            $sourceRows = (int) (preg_match('/(?:^|;)projected_rows=([0-9]+)/', $counts, $projected) ? $projected[1] : 0);
            $stateBuilds = (int) (preg_match('/(?:^|;)canonical_state_builds=([0-9]+)/', $counts, $builds) ? $builds[1] : 0);
            $stateReuses = (int) (preg_match('/(?:^|;)canonical_state_reuses=([0-9]+)/', $counts, $reuses) ? $reuses[1] : 0);
            expect($sourceRows)->toBe($size)
                ->and($stateBuilds)->toBe($size)
                ->and($stateReuses)->toBe($size)
                ->and($response->headers->get('X-CDS-Perf-Actor-Category'))->toBe(OrganizationalAccessService::CENRO_CHIEF);
            $samples[] = [
                'elapsed_ms' => round($elapsedMs, 2),
                'sql_queries' => (int) (preg_match('/(?:^|, )sql;dur=([0-9.]+);desc="queries ([0-9]+)"/', $timing, $sqlMatch) ? $sqlMatch[2] : 0),
                'sql_ms' => (float) ($sqlMatch[1] ?? 0),
                'projected_rows' => $sourceRows,
                'pamb_presentations' => (int) (preg_match('/(?:^|;)mov_presentations=([0-9]+)/', $counts, $mov) ? $mov[1] : 0),
                'canonical_presentations' => (int) (preg_match('/(?:^|;)canonical_routing_presentations=([0-9]+)/', $counts, $canonical) ? $canonical[1] : 0),
                'canonical_state_builds' => (int) (preg_match('/(?:^|;)canonical_state_builds=([0-9]+)/', $counts, $builds) ? $builds[1] : 0),
                'canonical_state_reuses' => (int) (preg_match('/(?:^|;)canonical_state_reuses=([0-9]+)/', $counts, $reuses) ? $reuses[1] : 0),
                'phases' => $metrics,
            ];
        }
        $samplesBySize[$size] = $samples;
    }

    $queryPhase = null;
    $queryFamilies = [];
    DB::listen(function (QueryExecuted $query) use (&$queryPhase, &$queryFamilies): void {
        if ($queryPhase === null) return;

        $sql = ltrim($query->sql);
        $verb = strtoupper((string) strtok($sql, " \t\r\n"));
        $table = 'other';
        if (preg_match('/\\b(?:from|join|into|update)\\s+["`\\[]?([a-zA-Z0-9_.]+)/i', $sql, $match)) {
            $table = strtolower($match[1]);
        }
        $key = $queryPhase.':'.$verb.':'.$table;
        $queryFamilies[$key] ??= ['count' => 0, 'sql_ms' => 0.0];
        $queryFamilies[$key]['count']++;
        $queryFamilies[$key]['sql_ms'] += max(0.0, (float) $query->time);
    });
    $queryFilters = ['protected_area_id' => $area->id];
    $queryPhase = 'pagination';
    $page = $tracking->pagination($queryFilters, 1, 25);
    $queryPhase = 'source_load';
    $projectedRecords = $tracking->records($queryFilters, null, false);
    $queryPhase = 'filter_options';
    $options = $tracking->filterOptions($queryFilters);
    $queryPhase = null;
    expect($page['total'])->toBe(60)
        ->and($projectedRecords)->toHaveCount(60)
        ->and($options['modules'])->not->toBeEmpty();

    if (getenv('CDS_NAV_PROFILE_OUTPUT') === '1') {
        $median = static function (array $values): float {
            sort($values);
            $count = count($values);
            return $count % 2 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
        };
        $summary = [];
        foreach ($samplesBySize as $size => $samples) {
            $warm = array_slice($samples, 1);
            $metricSummary = static function (array $items, callable $value) use ($median): array {
                $values = array_map($value, $items);
                return ['median' => round($median($values), 2), 'range' => [round(min($values), 2), round(max($values), 2)]];
            };
            $warmPhases = [];
            foreach (['st_pagination', 'st_source_load', 'st_filter_options', 'st_workspace_queues', 'st_pamb_action_projection', 'st_document_routing', 'st_document_attachment_lookup', 'st_pamb_attachment_projection'] as $phase) {
                $warmPhases[$phase] = [
                    'ms' => $metricSummary($warm, fn (array $sample): float => $sample['phases'][$phase]['ms']),
                    'query_count' => $metricSummary($warm, fn (array $sample): float => $sample['phases'][$phase]['queries']),
                    'sql_ms' => $metricSummary($warm, fn (array $sample): float => $sample['phases'][$phase]['sql_ms']),
                ];
            }
            $summary[$size] = [
                'mix' => ['pamb_presentations' => $samples[0]['pamb_presentations'], 'bms_rows' => $size - $samples[0]['pamb_presentations']],
                'cold_sample' => $samples[0],
                'warm' => [
                    'elapsed_ms' => $metricSummary($warm, fn (array $sample): float => $sample['elapsed_ms']),
                    'sql_queries' => $metricSummary($warm, fn (array $sample): float => $sample['sql_queries']),
                    'sql_ms' => $metricSummary($warm, fn (array $sample): float => $sample['sql_ms']),
                    'phases' => $warmPhases,
                ],
            ];
        }
        fwrite(STDERR, "CENRO_CHIEF_QUERY_FAMILIES actor=CENRO_CDS_CHIEF mix=approximately_5_of_12_PAMB_remaining_BMS samples_per_size=5 only_size_1_sample_1_is_cold_in_process ".json_encode(['sizes' => $summary, 'sanitized_direct_method_families' => $queryFamilies], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});

test('selected details reuse the complete authorized workspace projection', function (): void {
    $actor = User::factory()->create([
        'section' => OrganizationalAccessService::CENRO_FOCAL,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'office_designated' => 'CENRO Mati', 'is_approved' => true, 'is_active' => true,
    ]);
    foreach (['submission-tracking.view', 'technical-reports.view', 'technical-reports.update'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $area = ProtectedArea::create([
        'name' => 'Selected Snapshot Reuse PA', 'short_name' => 'SSRP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);
    $records = [];
    foreach (range(1, 10) as $index) {
        $records[] = ConservationReportSubmission::create([
            'workflow_key' => 'homestay', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
            'activity_name' => 'Selected snapshot '.$index, 'document_type' => 'Report',
            'reporting_period' => 'Q3', 'date_accomplished' => '2026-08-03',
            'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    $capture = false;
    $conservationReads = 0;
    $routingEventReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$capture, &$conservationReads, &$routingEventReads): void {
        if (! $capture) return;
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'conservation_report_submissions')) $conservationReads++;
        if (str_contains($sql, 'document_routing_events')) $routingEventReads++;
    });
    $measure = function (array $parameters) use (&$capture, &$conservationReads, &$routingEventReads, $actor): array {
        $conservationReads = 0;
        $routingEventReads = 0;
        $capture = true;
        $response = test()->actingAs($actor)
            ->get(route('submission-tracking.index', $parameters));
        $capture = false;
        $response->assertOk();
        return [$conservationReads, $routingEventReads, $response->inertiaProps()];
    };

    [$unselectedReads, $unselectedEventReads, $unselected] = $measure([]);
    [$selectedReads, $selectedEventReads, $selected] = $measure(['source' => 'conservation', 'source_id' => $records[4]->id]);
    expect($selectedReads)->toBeLessThanOrEqual($unselectedReads)
        ->and($unselectedEventReads)->toBeLessThanOrEqual(1)
        ->and($selectedEventReads)->toBeLessThanOrEqual(1)
        ->and($unselected['workspaceQueues']['incoming'])->toHaveCount(10)
        ->and($selected['workspaceQueues']['incoming'])->toHaveCount(10)
        ->and($selected['trackingContext']['selected_record']['source'])->toBe('conservation')
        ->and($selected['trackingContext']['selected_record']['source_id'])->toBe($records[4]->id);
});
