<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ReportTrackingReference;
use App\Models\User;
use App\Services\Reports\ReportTrackingNumberService;
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

    expect($numbers['conservation:'.$pa->id])->toBe('EDATS-PA-2026-000001')
        ->and($numbers['engp:'.$engp->id])->toBe('EDATS-ENGP-2026-000001')
        ->and(ReportTrackingReference::count())->toBe(2);
});

test('tracking identity is idempotent and sequences reset by domain and reporting year', function (): void {
    $service = app(ReportTrackingNumberService::class);
    $first = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'First', 'date_accomplished' => '2026-01-01']);
    $second = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Second', 'date_accomplished' => '2026-01-02']);
    $future = ConservationReportSubmission::create(['workflow_key' => 'homestay', 'activity_name' => 'Future', 'date_accomplished' => '2027-01-01']);

    $firstMap = $service->ensureFor(collect([trackingCandidate($first, 'conservation'), trackingCandidate($second, 'conservation'), trackingCandidate($future, 'conservation')]));
    $secondMap = $service->ensureFor(collect([trackingCandidate($first->fresh(), 'conservation')]));

    expect($firstMap['conservation:'.$first->id])->toBe('EDATS-PA-2026-000001')
        ->and($firstMap['conservation:'.$second->id])->toBe('EDATS-PA-2026-000002')
        ->and($firstMap['conservation:'.$future->id])->toBe('EDATS-PA-2027-000001')
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

test('Submission Tracking exposes the stable reference without changing routing fields', function (): void {
    $user = User::factory()->create(['section' => 'CDS']);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Tracked report', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => null, 'date_received_penro' => null,
    ]);

    $this->actingAs($user);
    $row = app(SubmissionTrackingService::class)->records(['program' => 'conservation'])->firstWhere('source_id', $report->id);

    expect($row['tracking_number'])->toBe('EDATS-PA-2026-000001')
        ->and($row['submission_status'])->toBe('Pending Submission by CENRO')
        ->and($report->fresh()->date_received_penro)->toBeNull();
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
