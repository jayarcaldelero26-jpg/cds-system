<?php

use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

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
        if (str_contains(strtolower($query->sql), 'protected_area_office_assignments')) $assignmentQueries++;
    });

    $tracking = app(SubmissionTrackingService::class);
    $this->actingAs($actor);
    expect($tracking->records([], null, false))->toHaveCount(1);
    $singleRowQueryCount = $assignmentQueries;

    for ($index = 2; $index <= 20; $index++) trackingPerformanceReport($area, $actor, $index);
    $assignmentQueries = 0;
    $largeRows = $tracking->records([], null, false);
    expect($largeRows)->toHaveCount(20)
        ->and($assignmentQueries)->toBeLessThanOrEqual($singleRowQueryCount);
});
