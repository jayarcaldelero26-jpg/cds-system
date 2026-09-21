<?php

use App\Models\ConservationReportSubmission;
use App\Models\User;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function p2Runtime006Actor(string $section): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => $section,
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    $role = Role::findOrCreate($section, 'web');
    $role->syncPermissions([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('technical-reports.view', 'web'),
        Permission::findOrCreate('technical-reports.update', 'web'),
    ]);
    $user->assignRole($role);

    return $user;
}

function p2Runtime006SpecialReport(User $owner): ConservationReportSubmission
{
    return ConservationReportSubmission::create([
        'workflow_key' => 'special_pamb',
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'UAT-P2 special recommendation presentation',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3',
        'date_conducted' => '2026-09-01',
        'date_accomplished' => '2026-09-01',
        'date_report_released_cenro' => '2026-09-02',
        'date_received_penro' => '2026-09-03',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function p2Runtime006ReachRecommendation(): array
{
    $records = p2Runtime006Actor('PENRO_RECORDS');
    $office = p2Runtime006Actor('OFFICE_OF_THE_PENRO');
    $tsd = p2Runtime006Actor('PENRO_TSD_CHIEF');
    $focal = p2Runtime006Actor('PENRO_CDS_FOCAL');
    $chief = p2Runtime006Actor('PENRO_CDS_CHIEF');
    $report = p2Runtime006SpecialReport($office);
    $timeline = app(PambRoutingTimelineService::class);

    foreach ([
        [PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, $records],
        [PambRoutingTimelineService::RECEIVED_BY_PENRO, $office],
        [PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, $office],
        [PambRoutingTimelineService::RECEIVED_BY_TSD, $tsd],
        [PambRoutingTimelineService::FORWARDED_TSD_TO_CDS, $tsd],
        [PambRoutingTimelineService::RECEIVED_BY_CDS, $focal],
        [PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF, $focal],
        [PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, $chief],
    ] as [$stage, $actor]) {
        $timeline->record($report->fresh(), $stage, '2026-09-03 09:00:00', $actor->id);
    }

    return [$report, $chief, $office];
}

test('Special PAMB exposes the recommendation action to the current PENRO CDS Chief', function (): void {
    [$report, $chief] = p2Runtime006ReachRecommendation();

    $this->actingAs($chief);
    $row = app(SubmissionTrackingService::class)->records(['program' => 'conservation'])
        ->firstWhere('source_id', $report->id);
    $current = collect($row['routing_timeline'])->firstWhere('status', 'current');

    expect($row['workflow_key'])->toBe('special_pamb')
        ->and($row['routing_summary']['next_expected_action'])->toBe('Recommend to Office of the PENRO')
        ->and($row['can_transition'])->toBeTrue()
        ->and($current['key'])->toBe(PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO)
        ->and($current['can_record'])->toBeTrue()
        ->and($current['action_label'])->toBe('Recommend to Office of the PENRO');
});

test('Special PAMB recommendation is authorized only for the current PENRO CDS Chief and transfers ownership', function (): void {
    [$report, $chief, $office] = p2Runtime006ReachRecommendation();

    $this->actingAs($chief)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
    ]), [
        'stage' => PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
    ])->assertSessionHasNoErrors();

    $report = $report->fresh();
    expect($report->routingEvents()->where('stage_key', PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO)->count())->toBe(1)
        ->and(collect(app(PambRoutingTimelineService::class)->present($report)['timeline'])->firstWhere('status', 'current')['key'])
        ->toBe(PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL)
        ->and(app(SubmissionTrackingService::class)->workspaceQueues()['incoming']->pluck('source_id')->all())->not->toContain($report->id);

    $this->actingAs($office);
    expect(app(SubmissionTrackingService::class)->workspaceQueues()['incoming']->pluck('source_id')->all())->toContain($report->id);

    $wrong = p2Runtime006Actor('CENRO_CDS_CHIEF');
    $this->actingAs($wrong)->post(route('submission-tracking.internal-routing', [
        'conservation', $report->id, PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO,
    ]), ['stage' => PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO])->assertForbidden();

    $wrongRow = app(SubmissionTrackingService::class)->records(['program' => 'conservation'])
        ->firstWhere('source_id', $report->id);
    expect($wrongRow)->toBeNull();
});
