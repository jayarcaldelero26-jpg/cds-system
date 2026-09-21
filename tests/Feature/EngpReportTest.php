<?php

use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'office_designated' => 'CENRO Baganga', 'unit_assignment' => null]);
    foreach (['reports.view', 'technical-reports.view', 'technical-reports.create', 'technical-reports.update', 'technical-reports.delete'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    Storage::fake('local');
    Storage::fake('public');
});

function engpPayload(array $overrides = []): array
{
    return [...[
        'workflow_key' => 'cbep', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'Community-Based Employment Program (CBEP)', 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => '2026-01', 'period_label' => 'January 2026', 'deadline_submission' => '2026-01-20',
    ], ...$overrides];
}

test('ENGP registry contains exactly twelve workflows, office exception, and exact period rules', function () {
    $registry = app(EngpReportWorkflowRegistry::class);

    expect($registry->keys())->toBe(['cbep', 'elcac', 'ngp_staff_accomplishment', 'forest_disturbance', 'monthly_accomplishment_pmd_fmb', 'cenro_nursery_seedling', 'tree_replacement', 'rims', 'ngp_produce', 'nursery_maintenance', 'site_visit', 'weekly_accomplishment'])
        ->and($registry->find('ngp_staff_accomplishment')['offices'])->not->toContain('CENRO Manay')
        ->and($registry->periods('weekly_accomplishment', 2026))->toHaveCount(51)
        ->and($registry->deadline('cbep', 2026, '2026-01'))->toBe('2026-01-20')
        ->and($registry->deadline('rims', 2026, '2026-01'))->toBe('2026-01-29')
        ->and($registry->deadline('ngp_produce', 2026, 'Q1'))->toBe('2026-03-10')
        ->and($registry->deadline('ngp_produce', 2026, 'Q4'))->toBe('2026-12-10')
        ->and($registry->releaseComponents('ngp_produce', 2026, 'Q1'))->toHaveCount(3)
        ->and($registry->releaseComponents('ngp_produce', 2026, 'Q1')[0]['key'])->toBe('2026-01')
        ->and($registry->deadline('weekly_accomplishment', 2026, 'W01'))->toBe('2026-01-20')
        ->and($registry->deadline('weekly_accomplishment', 2026, 'W02'))->toBe('2026-01-20')
        ->and($registry->deadline('weekly_accomplishment', 2026, 'W03'))->toBe('2026-01-22');

    foreach (['cbep', 'elcac', 'ngp_staff_accomplishment', 'forest_disturbance', 'monthly_accomplishment_pmd_fmb', 'cenro_nursery_seedling', 'tree_replacement', 'rims'] as $workflow) {
        expect($registry->deadline($workflow, 2026, '2026-02'))->toBe('2026-02-20');
    }
});

test('ENGP uses signed calendar-day compliance and source timeliness thresholds', function () {
    expect((new EngpReportSubmission(engpPayload(['date_received_penro' => '2026-01-18'])))->days_complied)->toBe(2)
        ->and((new EngpReportSubmission(engpPayload(['date_received_penro' => '2026-01-17'])))->timeliness_rating)->toBe('Outstanding')
        ->and((new EngpReportSubmission(engpPayload(['date_received_penro' => '2026-01-18'])))->timeliness_rating)->toBe('Very Satisfactory')
        ->and((new EngpReportSubmission(engpPayload(['date_received_penro' => '2026-01-19'])))->timeliness_rating)->toBe('Satisfactory')
        ->and((new EngpReportSubmission(engpPayload(['date_received_penro' => '2026-01-20'])))->timeliness_rating)->toBe('Unsatisfactory')
        ->and((new EngpReportSubmission(engpPayload(['date_received_penro' => '2026-01-22'])))->timeliness_rating)->toBe('Poor');
});

test('ENGP tracking uses the canonical CENRO-to-PENRO route instead of release components', function () {
    $report = EngpReportSubmission::create(engpPayload([
        'workflow_key' => 'ngp_produce', 'activity_name' => 'ENGP Produce', 'document_type' => 'Quarterly Report',
        'period_key' => 'Q1', 'period_label' => 'Quarter 1', 'deadline_submission' => '2026-03-10',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]));
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($this->user);
    $tracking->transition('engp', $report->id, 'forward_to_cenro_chief', null, $this->user->id);

    $row = $tracking->records()->firstWhere('source_id', $report->id);
    expect($report->releaseEvents()->count())->toBe(0)
        ->and($row['routing']['current_stage'])->toBe('transit_to_cenro_chief')
        ->and(app(SubmissionTrackingService::class)->genericTransitionKeys('engp', $report->id))->toContain('receive_at_cenro_chief');
});

test('ENGP report creation is optional-MOV and its ordinary alert closes at PENRO receipt', function () {
    $report = EngpReportSubmission::create(engpPayload(['date_received_penro' => null, 'deadline_submission' => '2026-01-20', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]));
    $alerts = app(OverdueReportService::class);
    $overdue = $alerts->overdueReports(CarbonImmutable::parse('2026-01-21', 'Asia/Manila'))->firstWhere('sourceId', $report->id);
    expect($overdue)->not->toBeNull()->and($overdue->complianceIssue)->toBe('Report Not Yet Submitted')->and($overdue->movRequired)->toBeFalse();
    $report->update(['date_received_penro' => '2026-01-21']);
    expect($alerts->overdueReports(CarbonImmutable::parse('2026-01-22', 'Asia/Manila'))->firstWhere('sourceId', $report->id))->toBeNull();
});

test('ENGP external MOV references accept HTTPS only', function () {
    $this->actingAs($this->user)
        ->post(route('engp-reports.store', 'site_visit'), [
            'office' => 'CENRO Baganga',
            'section_name' => 'NGP',
            'reporting_year' => 2026,
            'period_key' => 'Q1',
            'mov_external_url' => 'http://example.com/mov.pdf',
        ])
        ->assertSessionHasErrors('mov_external_url');

    expect(EngpReportSubmission::query()->where('workflow_key', 'site_visit')->count())->toBe(0);
});

test('ENGP accepts future reporting years and rejects years outside the supported range', function () {
    $this->actingAs($this->user)
        ->post(route('engp-reports.store', 'cbep'), [
            'office' => 'CENRO Baganga',
            'section_name' => 'NGP',
            'reporting_year' => 2027,
            'period_key' => '2027-01',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('engp_report_submissions', [
        'workflow_key' => 'cbep',
        'office' => 'CENRO Baganga',
        'reporting_year' => 2027,
        'period_key' => '2027-01',
        'deadline_submission' => '2027-01-20',
    ]);

    $this->actingAs($this->user)
        ->from(route('engp-reports.index', 'cbep'))
        ->post(route('engp-reports.store', 'cbep'), [
            'office' => 'CENRO Baganga',
            'reporting_year' => 1999,
            'period_key' => '1999-01',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('reporting_year');
});

test('ENGP store updates an existing submission instead of inserting a duplicate period', function () {
    $existing = EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'Original Section',
        'activity_name' => 'ENGP Site Visit Report', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'Q1', 'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10', 'date_received_penro' => '2026-03-11', 'remarks' => 'Original remarks',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->post(route('engp-reports.store', 'site_visit'), [
        'office' => 'CENRO Baganga', 'section_name' => 'Updated Section',
        'reporting_year' => 2026, 'period_key' => 'Q1',
        'remarks' => 'Updated remarks',
    ]);

    $response->assertRedirect();
    expect(EngpReportSubmission::query()->where('workflow_key', 'site_visit')->where('office', 'CENRO Baganga')->where('reporting_year', 2026)->where('period_key', 'Q1')->count())->toBe(1);
    $this->assertDatabaseHas('engp_report_submissions', [
        'id' => $existing->id, 'section_name' => 'Updated Section', 'remarks' => 'Updated remarks',
        'date_received_penro' => '2026-03-11', 'created_by' => $this->user->id,
    ]);
});

test('authorized ENGP users can advance a report to CENRO Chief through Submission Tracking', function () {
    $report = EngpReportSubmission::create(engpPayload([
        'workflow_key' => 'site_visit', 'activity_name' => 'ENGP Site Visit Report',
        'document_type' => 'Quarterly Report', 'period_key' => 'Q1', 'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]));

    $this->actingAs($this->user)
        ->post(route('submission-tracking.transition', ['engp', $report->id, 'forward_to_cenro_chief']), [
            'stage' => 'forward_to_cenro_chief',
        ])
        ->assertSessionHasNoErrors();

    expect($report->fresh()->releaseEvents()->count())->toBe(0)
        ->and(app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id)['routing']['current_stage'])->toBe('transit_to_cenro_chief');
});


test('ENGP PENRO receipt completes routing and makes the report immutable', function () {
    $chief = User::factory()->create(['section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Baganga']);
    $records = User::factory()->create(['section' => OrganizationalAccessService::CENRO_RECORDS, 'office_designated' => 'CENRO Baganga']);
    $penro = User::factory()->create(['section' => OrganizationalAccessService::PENRO_RECORDS, 'office_designated' => 'PENRO Davao Oriental']);
    foreach ([$chief, $records, $penro] as $actor) {
        $actor->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    }

    $report = EngpReportSubmission::create(engpPayload(['created_by' => $this->user->id, 'updated_by' => $this->user->id]));
    $tracking = app(SubmissionTrackingService::class);
    foreach ([
        [$this->user, 'forward_to_cenro_chief'],
        [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'],
        [$records, 'receive_at_cenro_records'],
        [$records, 'forward_to_penro_records'],
        [$penro, 'receive_at_penro_records'],
    ] as [$actor, $action]) {
        $tracking->transition('engp', $report->id, $action, null, $actor->id);
    }

    $report->refresh();
    $row = $tracking->records()->firstWhere('source_id', $report->id);
    expect($report->date_received_penro)->not->toBeNull()
        ->and($row['routing_complete'])->toBeTrue();

    $this->actingAs($this->user)
        ->from(route('engp-reports.index', 'cbep'))
        ->put(route('engp-reports.update', ['cbep', $report->id]), [
            'office' => 'CENRO Baganga', 'section_name' => 'Should remain unchanged',
            'reporting_year' => 2026, 'period_key' => '2026-01', 'remarks' => 'immutable check',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('submission');
});

test('ENGP administrative override records the generic routing event', function () {
    $report = EngpReportSubmission::create(engpPayload(['created_by' => $this->user->id, 'updated_by' => $this->user->id]));
    $event = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class)->transitionAsOverride(
        $report,
        'engp',
        'forward_to_cenro_chief',
        $this->user,
        ['override_for_category' => OrganizationalAccessService::CENRO_CHIEF, 'override_for_office' => 'CENRO Baganga'],
        'Controlled override test',
    );

    expect($event->source_type)->toBe('engp')
        ->and($event->metadata['administrative_override'])->toBeTrue()
        ->and($report->fresh()->date_received_penro)->toBeNull();
});
test('unauthorized users cannot perform an ENGP tracking transition', function () {
    $report = EngpReportSubmission::create(engpPayload([
        'workflow_key' => 'site_visit',
        'activity_name' => 'ENGP Site Visit Report',
        'document_type' => 'Quarterly Report',
        'period_key' => 'Q1',
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10',
    ]));
    $unauthorized = User::factory()->create();
    $unauthorized->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    $this->actingAs($unauthorized)
        ->post(route('submission-tracking.transition', ['engp', $report->id, SubmissionTrackingService::CENRO_RELEASE]), [
            'stage' => SubmissionTrackingService::CENRO_RELEASE,
            'date' => '2026-03-10',
        ])
        ->assertForbidden();

    expect($report->releaseEvents()->count())->toBe(0);
});

test('ENGP tracking transitions enforce originating office scope', function () {
    $report = EngpReportSubmission::create(engpPayload([
        'workflow_key' => 'site_visit',
        'activity_name' => 'ENGP Site Visit Report',
        'document_type' => 'Quarterly Report',
        'period_key' => 'Q1',
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10',
        'office' => 'CENRO Lupon',
    ]));
    $wrongOffice = User::factory()->create([
        'unit_assignment' => 'development',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $wrongOffice->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));

    $this->actingAs($wrongOffice)
        ->post(route('submission-tracking.transition', ['engp', $report->id, SubmissionTrackingService::CENRO_RELEASE]), [
            'stage' => SubmissionTrackingService::CENRO_RELEASE,
            'date' => '2026-03-10',
        ])
        ->assertForbidden();

    expect($report->releaseEvents()->count())->toBe(0);
});

test('ENGP ordinary updates reject routing fields and preserve existing routing dates', function () {
    $report = EngpReportSubmission::create(engpPayload([
        'workflow_key' => 'site_visit',
        'activity_name' => 'ENGP Site Visit Report',
        'document_type' => 'Quarterly Report',
        'period_key' => 'Q1',
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10',
        'date_received_penro' => '2026-03-11',
    ]));

    $this->actingAs($this->user)
        ->from(route('engp-reports.index', 'site_visit'))
        ->put(route('engp-reports.update', ['site_visit', $report->id]), [
            'office' => 'CENRO Baganga',
            'section_name' => 'Updated Section',
            'reporting_year' => 2026,
            'period_key' => 'Q1',
            'date_received_penro' => '2026-03-12',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('routing');

    expect($report->fresh()->date_received_penro?->toDateString())->toBe('2026-03-11');

    $this->actingAs($this->user)
        ->put(route('engp-reports.update', ['site_visit', $report->id]), [
            'office' => 'CENRO Baganga',
            'section_name' => 'Updated Section',
            'reporting_year' => 2026,
            'period_key' => 'Q1',
            'remarks' => 'Ordinary edit',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('submission');

    expect($report->fresh()->date_received_penro?->toDateString())->toBe('2026-03-11')
        ->and($report->fresh()->section_name)->toBe('NGP');
});

test('ENGP summary excludes the weekly accomplishment workflow', function () {
    $this->actingAs($this->user)->get(route('engp-reports.summary'))->assertOk()->assertInertia(fn ($page) => $page->component('Engp/Index')->where('workflow', null)->has('summary', 11));
});

test('ENGP summary counts remain within the authenticated development office scope', function (): void {
    $scopedUser = User::factory()->create([
        'unit_assignment' => 'development',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $scopedUser->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    EngpReportSubmission::create(engpPayload(['office' => 'CENRO Baganga', 'date_received_penro' => '2026-01-18']));
    EngpReportSubmission::create(engpPayload(['office' => 'CENRO Mati']));

    $this->actingAs($scopedUser)->get(route('engp-reports.summary'))
        ->assertInertia(fn ($page) => $page
            ->where('summary.0.workflow_key', 'cbep')
            ->where('summary.0.records', 1)
            ->has('summaryRows', 1)
            ->where('summaryRows.0.office', 'CENRO Baganga')
            ->where('summaryRows.0.monitoring_status', 'Report Submitted'));
});

test('scoped ENGP users receive only their authorized office choices', function (): void {
    $scopedUser = User::factory()->create([
        'unit_assignment' => 'development',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $scopedUser->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));

    $this->actingAs($scopedUser)->get(route('engp-reports.index', 'cbep'))
        ->assertInertia(fn ($page) => $page->where('offices', ['CENRO Baganga']));
});

test('ENGP index accepts the reporting_year query alias and returns the selected year schedule', function (): void {
    $this->actingAs($this->user)
        ->get(route('engp-reports.index', ['workflow' => 'cbep', 'reporting_year' => 2027]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('year', 2027)
            ->where('filters.year', 2027)
            ->where('periods.0.key', '2027-01')
            ->where('periods.11.key', '2027-12')
            ->where('periodsByYear.2027.0.key', '2027-01')
            ->where('years', fn ($years): bool => collect($years)->contains(2027)));
});

test('ENGP index normalizes an invalid year query to the current reporting year', function (): void {
    $this->actingAs($this->user)
        ->get(route('engp-reports.index', ['workflow' => 'cbep', 'year' => 9999]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('year', CarbonImmutable::now('Asia/Manila')->year));
});
