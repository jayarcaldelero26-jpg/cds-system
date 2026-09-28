<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\ImeaReportSubmission;
use App\Models\Aws;
use App\Models\IpafManagementReport;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ProtectedArea;
use App\Models\ReportTrackingReference;
use App\Models\User;
use App\Services\Reports\ReportTrackingNumberService;
use App\Services\Reports\ReportTrackingReferenceLifecycle;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
});

function trackingCandidate($record, string $key): array
{
    return ['record' => $record, 'key' => $key];
}

test('actual PA and ENGP submissions receive stable independent human-readable references', function (): void {
    $pa = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Homestay', 'date_accomplished' => '2026-08-01']);
    $engp = EngpReportSubmission::create([
        'workflow_key' => 'cbep', 'office' => 'CENRO Baganga', 'activity_name' => 'CBEP', 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => '2026-08', 'period_label' => 'August 2026', 'deadline_submission' => '2026-09-20',
    ]);
    $service = app(ReportTrackingNumberService::class);
    $numbers = $service->ensureFor(collect([trackingCandidate($pa, 'conservation'), trackingCandidate($engp, 'engp')]));

    expect($numbers['conservation:'.$pa->id])->toBe('2026-CDS-000001')
        ->and($numbers['engp:'.$engp->id])->toBe('2026-CDS-000002')
        ->and(ReportTrackingReference::count())->toBe(2);
});

test('tracking identity is idempotent and sequences reset by domain and reporting year', function (): void {
    $service = app(ReportTrackingNumberService::class);
    $first = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'First', 'date_accomplished' => '2026-01-01']);
    $second = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Second', 'date_accomplished' => '2026-01-02']);
    $future = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Future', 'date_accomplished' => '2027-01-01']);

    $firstMap = $service->ensureFor(collect([trackingCandidate($first, 'conservation'), trackingCandidate($second, 'conservation'), trackingCandidate($future, 'conservation')]));
    $secondMap = $service->ensureFor(collect([trackingCandidate($first->fresh(), 'conservation')]));

    expect($firstMap['conservation:'.$first->id])->toBe('2026-CDS-000001')
        ->and($firstMap['conservation:'.$second->id])->toBe('2026-CDS-000002')
        ->and($firstMap['conservation:'.$future->id])->toBe('2027-CDS-000001')
        ->and($secondMap['conservation:'.$first->id])->toBe($firstMap['conservation:'.$first->id])
        ->and(ReportTrackingReference::count())->toBe(3);
});

test('historical backfill is idempotent and supports dry run', function (): void {
    ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Legacy', 'date_accomplished' => '2026-01-01']);
    $service = app(ReportTrackingNumberService::class);

    expect($service->backfill(true)['created'])->toBe(1)->and(ReportTrackingReference::count())->toBe(0);
    expect($service->backfill(false)['created'])->toBe(1)->and($service->backfill(false)['created'])->toBe(0);
    expect(ReportTrackingReference::count())->toBe(1);
});

test('existing historical EDATS references are preserved unchanged', function (): void {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'activity_name' => 'Historical reference',
        'date_accomplished' => '2026-01-01',
    ]);
    ReportTrackingReference::create([
        'tracking_number' => 'EDATS-PA-2026-000014',
        'domain' => 'PA',
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'reporting_year' => 2026,
    ]);

    $number = app(ReportTrackingNumberService::class)->ensureFor(collect([
        trackingCandidate($report->fresh(), 'conservation'),
    ]))['conservation:'.$report->id];

    expect($number)->toBe('EDATS-PA-2026-000014')
        ->and(ReportTrackingReference::query()->where('source_id', $report->id)->value('tracking_number'))
        ->toBe('EDATS-PA-2026-000014');
});

test('tracked source deletion tombstones each supported deletable family and preserves its number', function (): void {
    $user = User::factory()->create();
    $area = ProtectedArea::create(['name' => 'Tracking Lifecycle PA', 'short_name' => 'TLP', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $common = ['protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Lifecycle test', 'document_type' => 'Final Report', 'date_accomplished' => '2026-08-01', 'created_by' => $user->id, 'updated_by' => $user->id];
    $sources = [
        ['bms', BmsReportSubmission::create([...$common, 'semester' => '1st Semester'])],
        ['conservation', ConservationReportSubmission::create([...$common, 'workflow_key' => 'homestay'])],
        ['engp', EngpReportSubmission::create(['workflow_key' => 'cbep', 'office' => 'CENRO Mati', 'activity_name' => 'Lifecycle test', 'document_type' => 'Monthly Report', 'reporting_year' => 2026, 'period_key' => '2026-08', 'period_label' => 'August 2026', 'deadline_submission' => '2026-09-20'])],
        ['aws', Aws::create([...$common, 'station_name' => 'Lifecycle station', 'location' => 'Mati'])],
        ['ipaf-management', IpafManagementReport::create([...$common])],
        ['imea-maintenance', ImeaFacilityMaintenanceReport::create([...$common, 'quarter' => 'Q3'])],
        ['revenue', IpafRevenueCollection::create([...collect($common)->except('date_accomplished')->all(), 'activity_name' => 'Revenue Collection', 'reporting_month' => 8, 'reporting_year' => 2026, 'total_collected' => '100.00'])],
        ['management-plans', ManagementPlan::create([...$common, 'management_plan_type_id' => null, 'plan_type' => 'Test plan', 'title' => 'Lifecycle test', 'version' => '1', 'prepared_year' => 2026, 'status' => 'Pending'])],
    ];
    $trackingNumbers = app(ReportTrackingNumberService::class);
    $lifecycle = app(ReportTrackingReferenceLifecycle::class);

    foreach ($sources as [$family, $source]) {
        $trackingNumber = $trackingNumbers->ensureFor(collect([trackingCandidate($source, $family)]))[$family.':'.$source->id];
        $lifecycle->deleteSource($source, fn () => $source->delete());
        $reference = ReportTrackingReference::query()->where('source_type', $family)->where('source_id', $source->id)->firstOrFail();

        expect($reference->tracking_number)->toBe($trackingNumber)
            ->and($reference->source_deleted_at)->not->toBeNull()
            ->and($trackingNumbers->classifyReference($reference))->toBe('tombstoned')
            ->and($trackingNumbers->allocate(['source_type' => $family, 'source_id' => $source->id, 'domain' => 'CDS', 'reporting_year' => 2026]))->toBe($trackingNumber);
    }

    $conservationReference = ReportTrackingReference::query()->where('source_type', 'conservation')->firstOrFail();
    $nextSource = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Next issued source', 'date_accomplished' => '2026-08-02']);
    $nextNumber = $trackingNumbers->ensureFor(collect([trackingCandidate($nextSource, 'conservation')]))['conservation:'.$nextSource->id];
    $resolved = $trackingNumbers->resolveReference($conservationReference);

    expect($nextNumber)->not->toBe($conservationReference->tracking_number)
        ->and($resolved['status'])->toBe('tombstoned')
        ->and($resolved['source'])->toBeNull()
        ->and($resolved['tracking_number'])->toBe($conservationReference->tracking_number)
        ->and(app(SubmissionTrackingService::class)->search($conservationReference->tracking_number))->toBeEmpty();
});

test('failed source deletion rolls back the tombstone and keeps the source and reservation live', function (): void {
    $source = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Rollback', 'date_accomplished' => '2026-08-01']);
    $numbers = app(ReportTrackingNumberService::class);
    $trackingNumber = $numbers->ensureFor(collect([trackingCandidate($source, 'conservation')]))['conservation:'.$source->id];

    try {
        app(ReportTrackingReferenceLifecycle::class)->deleteSource($source, function (): never {
            throw new RuntimeException('Simulated source deletion failure.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated source deletion failure.');
    }

    $reference = ReportTrackingReference::query()->where('source_type', 'conservation')->where('source_id', $source->id)->firstOrFail();
    expect($source->fresh())->not->toBeNull()
        ->and($reference->source_deleted_at)->toBeNull()
        ->and($numbers->classifyReference($reference))->toBe('live')
        ->and($reference->tracking_number)->toBe($trackingNumber);
});

test('unmarked missing source remains an integrity defect while tombstones remain historical', function (): void {
    $broken = ReportTrackingReference::query()->create(['tracking_number' => '2026-CDS-900001', 'domain' => 'CDS', 'source_type' => 'conservation', 'source_id' => 999999, 'reporting_year' => 2026]);

    $resolution = app(ReportTrackingNumberService::class)->resolveReference($broken);
    expect($resolution['status'])->toBe('unexpected_missing_source')
        ->and($resolution['source'])->toBeNull()
        ->and($resolution['tracking_number'])->toBe('2026-CDS-900001');
});

test('Submission Tracking exposes the stable reference without changing routing fields', function (): void {
    $user = User::factory()->create(['section' => 'CDS']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Tracked report', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => null, 'date_received_penro' => null,
    ]);

    $this->actingAs($user);
    $row = app(SubmissionTrackingService::class)->records(['program' => 'conservation'], null, true)->firstWhere('source_id', $report->id);

    expect($row['tracking_number'])->toBe('2026-CDS-000001')
        ->and($row['submission_status'])->toBe('Pending Submission by CENRO')
        ->and($report->fresh()->date_received_penro)->toBeNull();
});

test('Submission Tracking page presentation does not allocate missing references', function (): void {
    $user = User::factory()->create(['section' => 'CDS']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'activity_name' => 'Read-only tracking page report',
        'date_accomplished' => '2026-08-01',
    ]);

    $this->actingAs($user)
        ->get(route('submission-tracking.index'))
        ->assertOk();

    expect(ReportTrackingReference::query()
        ->where('source_type', 'conservation')
        ->where('source_id', $report->id)
        ->exists())->toBeFalse();
});

test('tracking lookup survives edits and operational routing while authorization remains enforced', function (): void {
    $user = User::factory()->create(['section' => 'CDS']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Stable lookup report', 'date_accomplished' => '2026-08-01',
    ]);

    $this->actingAs($user);
    $service = app(SubmissionTrackingService::class);
    $number = app(ReportTrackingNumberService::class)->ensureFor(collect([trackingCandidate($report, 'conservation')]))['conservation:'.$report->id];

    expect($service->search($number)->firstWhere('tracking_number', $number))->not->toBeNull();

    $report->update(['activity_name' => 'Edited stable lookup report']);
    $report->update(['date_received_penro' => '2026-08-02']);
    $afterRouting = app(ReportTrackingNumberService::class)->ensureFor(collect([trackingCandidate($report->fresh(), 'conservation')]))['conservation:'.$report->id];

    $this->actingAs(User::factory()->create(['section' => 'CDS']))->get(route('submission-tracking.index'))->assertForbidden();

    expect($afterRouting)->toBe($number);
});
