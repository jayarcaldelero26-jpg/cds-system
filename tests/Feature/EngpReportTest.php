<?php

use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create();
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

test('quarterly ENGP tracking preserves three monthly CENRO releases and bypasses Regional Endorsement', function () {
    $report = EngpReportSubmission::create(engpPayload(['workflow_key' => 'ngp_produce', 'activity_name' => 'ENGP Produce', 'document_type' => 'Quarterly Report', 'period_key' => 'Q1', 'period_label' => 'Quarter 1', 'deadline_submission' => '2026-03-10', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]));
    $tracking = app(SubmissionTrackingService::class);
    foreach (['2026-01-10', '2026-02-10', '2026-03-10'] as $date) {
        $tracking->transition('engp', $report->id, SubmissionTrackingService::CENRO_RELEASE, $date, $this->user->id);
    }
    expect($report->releaseEvents()->count())->toBe(3)
        ->and($tracking->records()->firstWhere('source_id', $report->id)['stage'])->toBe(SubmissionTrackingService::PENRO_RECEIPT);

    $tracking->transition('engp', $report->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-03-11', $this->user->id);
    expect($tracking->queues()[SubmissionTrackingService::REGIONAL_ENDORSEMENT]->where('source_id', $report->id))->toBeEmpty()
        ->and($tracking->queues()['history']->firstWhere('source_id', $report->id))->not->toBeNull();
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

test('authorized ENGP users can advance a report through CENRO release and PENRO receipt', function () {
    $report = EngpReportSubmission::create(engpPayload([
        'workflow_key' => 'site_visit',
        'activity_name' => 'ENGP Site Visit Report',
        'document_type' => 'Quarterly Report',
        'period_key' => 'Q1',
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]));

    foreach (['2026-01-10', '2026-02-10', '2026-03-10'] as $date) {
        $this->actingAs($this->user)
            ->post(route('submission-tracking.transition', ['engp', $report->id, SubmissionTrackingService::CENRO_RELEASE]), [
                'stage' => SubmissionTrackingService::CENRO_RELEASE,
                'date' => $date,
            ])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->user)
        ->post(route('submission-tracking.transition', ['engp', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
            'stage' => SubmissionTrackingService::PENRO_RECEIPT,
            'date' => '2026-03-11',
        ])
        ->assertSessionHasNoErrors();

    expect($report->fresh()->date_received_penro?->toDateString())->toBe('2026-03-11')
        ->and($report->releaseEvents()->count())->toBe(3);
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
        'office' => 'CENRO Cateel',
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
        ->assertRedirect();

    expect($report->fresh()->date_received_penro?->toDateString())->toBe('2026-03-11')
        ->and($report->fresh()->section_name)->toBe('Updated Section');
});

test('ENGP summary excludes the weekly accomplishment workflow', function () {
    $this->actingAs($this->user)->get(route('engp-reports.summary'))->assertOk()->assertInertia(fn ($page) => $page->component('Engp/Index')->where('workflow', null)->has('summary', 11));
});
