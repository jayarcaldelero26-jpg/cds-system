<?php

use App\Models\Aws;
use App\Models\AwsObservation;
use App\Models\BamsFlora;
use App\Models\BamsReportSubmission;
use App\Models\BmsRecord;
use App\Models\BmsReportSubmission;
use App\Models\ComplianceAlertRecipient;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaAssessment;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ManagementPlanType;
use App\Models\NonWorkingDay;
use App\Models\ProtectedArea;
use App\Services\BusinessCalendarService;
use App\Services\Compliance\ComplianceRecipientResolver;
use App\Services\Compliance\OverdueReportService;
use App\Services\Dashboard\DashboardMonitoringService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

function p1GlobalUser(): \App\Models\User
{
    $user = \App\Models\User::factory()->create(['is_active' => true, 'section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));

    return $user;
}

function p1Area(\App\Models\User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'P1 Parity Protected Area',
        'short_name' => 'P1PA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

function p1Common(ProtectedArea $area, \App\Models\User $user): array
{
    return [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Baganga',
        'date_accomplished' => '2026-08-28',
        'date_report_released_cenro' => null,
        'date_received_penro' => null,
        'date_endorsed_regional' => null,
        'mov_file_name' => 'p1-parity.pdf',
        'mov_file_path' => 'p1/parity.pdf',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ];
}

test('populated report sources preserve identity, deadline, scope, and status across tracking dashboard and alerts', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 10:00:00', 'Asia/Manila'));
    BusinessCalendarService::forgetCache();

    $user = p1GlobalUser();
    $area = p1Area($user);
    Storage::fake('public');
    NonWorkingDay::create([
        'date' => '2026-08-31',
        'name' => 'P1 Audit Holiday',
        'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    ComplianceAlertRecipient::query()->update(['is_active' => false]);
    $recipient = ComplianceAlertRecipient::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Baganga',
        'target_office_key' => 'cenro_baganga',
        'recipient_email' => 'p1-parity@example.test',
        'is_active' => true,
    ]);

    $conservation = [];
    foreach ([
        'homestay' => 'Homestay',
        'maintenance_monuments' => 'Maintenance of Monuments',
        'additional_bms_site' => 'Additional BMS Site',
        'ecotourism_management_plan' => 'Ecotourism Management Plan',
    ] as $workflow => $activity) {
        $conservation[$workflow] = ConservationReportSubmission::create([
            ...p1Common($area, $user),
            'workflow_key' => $workflow,
            'activity_name' => $activity,
            'document_type' => 'Final Report',
            'reporting_period' => '2026 Q3',
            'date_conducted' => '2026-08-28',
        ]);
    }

    $bms = BmsReportSubmission::create([...p1Common($area, $user), 'activity_name' => 'BMS report', 'document_type' => 'Final Report', 'semester' => '2nd Semester']);
    $bams = BamsReportSubmission::create([...p1Common($area, $user), 'activity_name' => 'BAMS report', 'document_type' => 'Final Report', 'semester' => '2nd Semester']);
    $imea = ImeaReportSubmission::create([...p1Common($area, $user), 'activity_name' => 'IMEA report', 'document_type' => 'Final Report', 'semester' => '2nd Semester']);
    $imeaMaintenance = ImeaFacilityMaintenanceReport::create([...p1Common($area, $user), 'activity_name' => 'IMEA Facility Maintenance', 'document_type' => 'Maintenance Report', 'quarter' => 'Q3']);
    $aws = Aws::create([
        ...p1Common($area, $user),
        'station_name' => 'P1 AWS Station', 'location' => 'P1 field site', 'activity_name' => 'AWS report',
        'document_type' => 'Quarterly Report', 'reporting_year' => 2026, 'quarter' => 3,
        'report_period_type' => 'Quarterly', 'monitoring_period_start' => '2026-07-01',
        'monitoring_period_end' => '2026-09-28', 'date_conducted' => '2026-08-28',
        'report_file_name' => 'aws.pdf', 'report_file_path' => 'p1/aws.pdf',
    ]);
    $ipaf = IpafManagementReport::create([...p1Common($area, $user), 'activity_name' => 'IPAF Management', 'document_type' => 'Final Report']);

    $models = [
        'conservation:'.$conservation['homestay']->id => [$conservation['homestay'], '2026-09-24'],
        'conservation:'.$conservation['maintenance_monuments']->id => [$conservation['maintenance_monuments'], '2026-09-10'],
        'conservation:'.$conservation['additional_bms_site']->id => [$conservation['additional_bms_site'], '2026-09-24'],
        'conservation:'.$conservation['ecotourism_management_plan']->id => [$conservation['ecotourism_management_plan'], '2026-09-10'],
        'bms:'.$bms->id => [$bms, '2026-09-24'],
        'bams:'.$bams->id => [$bams, '2026-09-24'],
        'imea:'.$imea->id => [$imea, '2026-09-24'],
        'imea-maintenance:'.$imeaMaintenance->id => [$imeaMaintenance, '2026-09-10'],
        'aws:'.$aws->id => [$aws, '2026-09-10'],
        'ipaf-management:'.$ipaf->id => [$ipaf, '2026-09-10'],
    ];

    $this->actingAs($user);
    $tracking = app(SubmissionTrackingService::class)->records(['reporting_year' => 2026], null, false)->keyBy(fn (array $row): string => $row['source'].':'.$row['source_id']);
    $dashboard = app(DashboardMonitoringService::class)->overview(['year' => 2026, 'program' => 'conservation'], false);
    $dashboardRows = collect($dashboard['rows'])->keyBy('id');
    $alerts = app(OverdueReportService::class)->overdueReports(CarbonImmutable::parse('2026-09-25', 'Asia/Manila'))
        ->keyBy(fn ($report): string => $report->sourceType.':'.$report->sourceId);

    expect($tracking)->toHaveCount(10)->and($dashboardRows)->toHaveCount(10)->and($alerts)->toHaveCount(10);
    foreach ($models as $key => [$model, $expectedDeadline]) {
        [$source, $id] = explode(':', $key, 2);
        $track = $tracking->get($key);
        $dash = $dashboardRows->get($source.'-'.$id);
        $alert = $alerts->get($model::class.':'.$model->id);
        expect($model->fresh()->deadline_submission)->toBe($expectedDeadline)
            ->and($track)->not->toBeNull()
            ->and($track['source_id'])->toBe($model->id)
            ->and($track['protected_area_id'])->toBe($area->id)
            ->and($track['target_office'])->toBe('CENRO Baganga')
            ->and($track['date_accomplished'])->toBe('2026-08-28')
            ->and($track['deadline_submission'])->toBe($expectedDeadline)
            ->and($track['date_received_penro'])->toBeNull()
            ->and($dash['id'])->toBe($source.'-'.$id)
            ->and($dash['protected_area_id'])->toBe($area->id)
            ->and($dash['target_office'])->toBe('CENRO Baganga')
            ->and($dash['date_accomplished'])->toBe('2026-08-28')
            ->and($dash['deadline_submission'])->toBe($expectedDeadline)
            ->and($dash['is_overdue'])->toBeTrue()
            ->and(data_get($dash, 'routing.current_stage'))->toBe(data_get($track, 'routing.current_stage'))
            ->and(data_get($dash, 'routing.current_location'))->toBe(data_get($track, 'routing.current_location'))
            ->and(data_get($dash, 'routing.current_status'))->toBe(data_get($track, 'routing.current_status'))
            ->and($alert)->not->toBeNull()
            ->and($alert->sourceId)->toBe($model->id)
            ->and($alert->protectedAreaId)->toBe($area->id)
            ->and($alert->targetOffice)->toBe('CENRO Baganga')
            ->and($alert->deadline)->toBe($expectedDeadline)
            ->and($alert->submitted)->toBeFalse();
    }

    expect($recipient->fresh()->logicalScopeKey())->toBe('pa:'.$area->id)
        ->and(app(ComplianceRecipientResolver::class)->resolve($alerts->first())->email)->toBe('p1-parity@example.test');
});

test('Revenue and ENGP preserve stored deadlines while PAMB preserves specialized deadlines', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 10:00:00', 'Asia/Manila'));
    $user = p1GlobalUser();
    $area = p1Area($user);
    ComplianceAlertRecipient::query()->update(['is_active' => false]);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'p1-special@example.test', 'is_active' => true]);

    $revenue = IpafRevenueCollection::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga', 'activity_name' => 'Revenue Collection',
        'document_type' => 'Monthly Report', 'reporting_month' => 8, 'reporting_year' => 2026,
        'total_collected' => '1000.00', 'deadline_submission' => '2026-09-20',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $engp = EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'ENGP report', 'document_type' => 'Monthly Report', 'reporting_year' => 2026,
        'period_key' => '2026-08', 'period_label' => 'August 2026', 'deadline_submission' => '2026-09-20',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $regular = ConservationReportSubmission::create([
        ...p1Common($area, $user), 'workflow_key' => 'regular_pamb', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => '2026 Q3', 'date_conducted' => '2026-08-28',
    ]);
    $special = ConservationReportSubmission::create([
        ...p1Common($area, $user), 'workflow_key' => 'special_pamb', 'activity_name' => 'Special PAMB',
        'document_type' => 'Minutes', 'reporting_period' => '2026 Q3', 'date_conducted' => '2026-08-28',
    ]);

    $tracking = app(SubmissionTrackingService::class)->records(['reporting_year' => 2026], null, false);
    $dashboard = app(DashboardMonitoringService::class)->overview(['year' => 2026], false);
    $alerts = app(OverdueReportService::class)->overdueReports(CarbonImmutable::parse('2026-09-25', 'Asia/Manila'));
    foreach ([$revenue, $engp, $regular, $special] as $model) {
        $source = $model instanceof IpafRevenueCollection ? 'revenue' : ($model instanceof EngpReportSubmission ? 'engp' : 'conservation');
        $track = $tracking->first(fn (array $row): bool => $row['source'] === $source && (int) $row['source_id'] === $model->id);
        $dash = collect($dashboard['rows'])->firstWhere('id', $source.'-'.$model->id);
        $alert = $alerts->first(fn ($item): bool => $item->sourceType === $model::class && $item->sourceId === $model->id);
        $modelDeadline = $model->deadline_submission instanceof \Carbon\CarbonInterface
            ? $model->deadline_submission->toDateString()
            : $model->deadline_submission;
        $trackingDeadline = $track['deadline_submission'] instanceof \Carbon\CarbonInterface
            ? $track['deadline_submission']->toDateString()
            : $track['deadline_submission'];
        expect($track)->not->toBeNull()->and($dash)->not->toBeNull()->and($alert)->not->toBeNull()
            ->and($trackingDeadline)->toBe($modelDeadline)
            ->and($dash['deadline_submission'])->toBe($trackingDeadline)
            ->and($alert->deadline)->toBe($trackingDeadline);
    }
    expect($revenue->deadline_submission->toDateString())->toBe('2026-09-20')
        ->and($engp->deadline_submission->toDateString())->toBe('2026-09-20')
        ->and($regular->deadline_submission)->not->toBe('2026-09-24')
        ->and($special->deadline_submission)->not->toBe('2026-09-24');
});

test('raw AWS, BMS, BAMS, and IMEA observations never enter report tracking, dashboard, or alerts', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 10:00:00', 'Asia/Manila'));
    $user = p1GlobalUser();
    $area = p1Area($user);
    $aws = AwsObservation::create(['protected_area_id' => $area->id, 'station_name' => 'Raw AWS', 'location' => 'Field', 'status' => 'Active', 'start_date' => '2026-08-28', 'end_date' => '2026-08-28']);
    $bms = BmsRecord::create(['protected_area_id' => $area->id, 'monitoring_date' => '2026-08-28', 'station' => 'BMS Station', 'category' => 'Flora', 'taxonomic_group' => 'Plants', 'species_common_name' => 'Raw BMS', 'species_scientific_name' => 'Raw bms species']);
    $bams = BamsFlora::create(['protected_area_id' => $area->id, 'plot_no' => 'P1', 'quadrat_no' => 1, 'date' => '2026-08-28', 'species_code' => 'RAW-BAMS']);
    $imea = ImeaAssessment::create(['protected_area_id' => $area->id, 'pamo_name' => 'Raw IMEA', 'assessment_year' => 2026, 'assessment_period' => 'Q3', 'status' => 'Draft', 'created_by' => $user->id, 'updated_by' => $user->id]);

    $this->actingAs($user);
    $tracking = app(SubmissionTrackingService::class)->records(['reporting_year' => 2026], null, false);
    $dashboard = app(DashboardMonitoringService::class)->overview(['year' => 2026], false);
    $alerts = app(OverdueReportService::class)->overdueReports(CarbonImmutable::parse('2026-09-25', 'Asia/Manila'));
    foreach ([$aws, $bms, $bams, $imea] as $raw) {
        expect($tracking->contains(fn (array $row): bool => (int) $row['source_id'] === $raw->id))->toBeFalse();
        expect(collect($dashboard['rows'])->contains(fn (array $row): bool => str_ends_with((string) $row['id'], '-'.$raw->id)))->toBeFalse();
        expect($alerts->contains(fn ($alert): bool => $alert->sourceId === $raw->id))->toBeFalse();
    }
});

test('due-soon, due-today, overdue, and completed states stay aligned across layers', function (): void {
    $user = p1GlobalUser();
    $area = p1Area($user);
    Storage::fake('public');
    ComplianceAlertRecipient::query()->update(['is_active' => false]);
    ComplianceAlertRecipient::create(['protected_area_id' => $area->id, 'recipient_email' => 'p1-states@example.test', 'is_active' => true]);
    $calendar = app(BusinessCalendarService::class);
    $startForDeadline = function (string $deadline) use ($calendar): string {
        $start = CarbonImmutable::parse($deadline, 'Asia/Manila')->subDay();
        for ($attempt = 0; $attempt < 60; $attempt++) {
            if ($calendar->addConservationWorkingDays($start, 15, 'CENRO Baganga')->toDateString() === $deadline) {
                return $start->toDateString();
            }
            $start = $start->subDay();
        }
        throw new RuntimeException('Unable to derive a Conservation deadline fixture.');
    };
    $make = function (string $deadline, string $semester) use ($area, $user, $startForDeadline): BmsReportSubmission {
        return BmsReportSubmission::create([
            ...p1Common($area, $user),
            'activity_name' => 'BMS lifecycle '.$semester,
            'document_type' => 'Final Report',
            'semester' => $semester,
            'date_accomplished' => $startForDeadline($deadline),
        ]);
    };
    $dueTodayReport = $make('2026-09-23', '1st Semester');
    $overdueReport = $make('2026-09-22', '2nd Semester');
    $dueSoonReport = $make('2026-09-24', '3rd Semester');
    $completed = $make('2026-09-22', '4th Semester');
    $completed->update(['date_report_released_cenro' => '2026-09-23', 'date_received_penro' => '2026-09-23', 'date_endorsed_regional' => '2026-09-24']);
    Storage::disk('public')->put('p1/parity.pdf', 'completed MOV');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 10:00:00', 'Asia/Manila'));
    $this->actingAs($user);
    $today = CarbonImmutable::parse('2026-09-23', 'Asia/Manila');
    $tracking = app(SubmissionTrackingService::class)->records(['reporting_year' => 2026], null, false);
    $dashboard = collect(app(DashboardMonitoringService::class)->overview(['year' => 2026], false)['rows'])->keyBy('id');
    $alerts = app(OverdueReportService::class);
    $dueSoon = $alerts->dueSoonReports(3, $today);
    $dueToday = $alerts->dueTodayReports($today);
    $overdue = $alerts->overdueReports($today);

    expect($dueSoon->contains(fn ($item): bool => $item->sourceId === $dueSoonReport->id))->toBeTrue()
        ->and($dueToday->contains(fn ($item): bool => $item->sourceId === $dueTodayReport->id))->toBeTrue()
        ->and($overdue->contains(fn ($item): bool => $item->sourceId === $overdueReport->id))->toBeTrue()
        ->and($overdue->contains(fn ($item): bool => $item->sourceId === $completed->id))->toBeFalse();
    foreach ([$dueTodayReport, $overdueReport, $dueSoonReport, $completed] as $model) {
        $track = $tracking->first(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $model->id);
        $row = $dashboard->get('bms-'.$model->id);
        expect($track)->not->toBeNull()->and($row)->not->toBeNull()
            ->and($track['deadline_submission'])->toBe($model->deadline_submission)
            ->and($row['deadline_submission'])->toBe($track['deadline_submission'])
            ->and($row['submitted'])->toBe($model->date_received_penro !== null);
    }
    expect($dashboard->get('bms-'.$completed->id)['is_overdue'])->toBeFalse()
        ->and($dashboard->get('bms-'.$completed->id)['submitted'])->toBeTrue();
});

test('Management Plans preserve one source identity across model tracking dashboard and alerts through completion', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 10:00:00', 'Asia/Manila'));
    BusinessCalendarService::forgetCache();

    $user = p1GlobalUser();
    $area = p1Area($user);
    Storage::fake('public');
    $type = ManagementPlanType::create([
        'name' => 'UAT Parity Plan', 'slug' => 'uat-parity-plan',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $plan = ManagementPlan::create([
        ...p1Common($area, $user),
        'management_plan_type_id' => $type->id,
        'plan_type' => 'Ecotourism Management Plan',
        'activity_name' => 'UAT parity fixture',
        'document_type' => 'Final Report',
        'date_accomplished' => '2026-08-28',
        'status' => 'Pending',
    ]);

    $sourceDeadline = $plan->fresh()->deadline_submission;
    expect($sourceDeadline)->toBe('2026-09-09')
        ->and((int) $plan->protected_area_id)->toBe($area->id)
        ->and($plan->target_office)->toBe('CENRO Baganga')
        ->and($plan->date_received_penro)->toBeNull()
        ->and($plan->date_endorsed_regional)->toBeNull();

    $this->actingAs($user);
    $tracking = app(SubmissionTrackingService::class)->records([
        'focus_source' => 'management-plans', 'focus_id' => $plan->id,
    ], null, true)->sole();
    $dashboardRows = collect(app(DashboardMonitoringService::class)->overview([
        'year' => 2026, 'program' => 'conservation', 'protected_area_id' => $area->id,
    ], false)['rows']);
    $dashboard = $dashboardRows->firstWhere('id', 'management-plans-'.$plan->id);
    $today = CarbonImmutable::parse('2026-09-08', 'Asia/Manila');
    $alerts = app(OverdueReportService::class);
    $dueSoon = $alerts->dueSoonReports(3, $today)->firstWhere('sourceId', $plan->id);

    expect($tracking['source'])->toBe('management-plans')
        ->and($tracking['source_id'])->toBe($plan->id)
        ->and($tracking['tracking_number'])->toStartWith('EDATS-PA-2026-')
        ->and((int) $tracking['protected_area_id'])->toBe($area->id)
        ->and($tracking['target_office'])->toBe('CENRO Baganga')
        ->and($tracking['deadline_submission'])->toBe($sourceDeadline)
        ->and($tracking['date_received_penro'])->toBeNull()
        ->and($tracking['routing_complete'])->toBeFalse()
        ->and($tracking['can_transition'])->toBeTrue()
        ->and($dashboard)->not->toBeNull()
        ->and($dashboard['id'])->toBe('management-plans-'.$plan->id)
        ->and((int) $dashboard['protected_area_id'])->toBe($area->id)
        ->and($dashboard['target_office'])->toBe('CENRO Baganga')
        ->and($dashboard['deadline_submission'])->toBe($sourceDeadline)
        ->and($dashboard['submitted'])->toBeFalse()
        ->and($dashboard['is_overdue'])->toBeFalse()
        ->and($dueSoon)->not->toBeNull()
        ->and($dueSoon->sourceType)->toBe(ManagementPlan::class)
        ->and($dueSoon->sourceId)->toBe($plan->id)
        ->and($dueSoon->protectedAreaId)->toBe($area->id)
        ->and($dueSoon->targetOffice)->toBe('CENRO Baganga')
        ->and($dueSoon->deadline)->toBe($sourceDeadline)
        ->and($dueSoon->submitted)->toBeFalse();

    $attachmentPath = 'uat-parity/management-plan.pdf';
    Storage::disk('public')->put($attachmentPath, 'UAT parity evidence');
    $plan->update([
        'date_report_released_cenro' => '2026-09-10',
        'date_received_penro' => '2026-09-11',
        'date_endorsed_regional' => '2026-09-12',
        'attachments' => [['path' => $attachmentPath, 'original_name' => 'management-plan.pdf']],
    ]);

    $completedTracking = app(SubmissionTrackingService::class)->records([
        'focus_source' => 'management-plans', 'focus_id' => $plan->id,
    ], null, false)->sole();
    $completedDashboard = collect(app(DashboardMonitoringService::class)->overview([
        'year' => 2026, 'program' => 'conservation', 'protected_area_id' => $area->id,
    ], false)['rows'])->firstWhere('id', 'management-plans-'.$plan->id);
    $completedQueues = app(SubmissionTrackingService::class)->queues([], collect([$completedTracking]));

    expect($completedTracking['routing_complete'])->toBeTrue()
        ->and($completedTracking['routing']['actions'])->toBeEmpty()
        ->and($completedQueues['active']->contains(fn (array $row): bool => $row['source'] === 'management-plans' && (int) $row['source_id'] === $plan->id))->toBeFalse()
        ->and($completedDashboard['submitted'])->toBeTrue()
        ->and($completedDashboard['is_overdue'])->toBeFalse()
        ->and($alerts->overdueReports(CarbonImmutable::parse('2026-09-13', 'Asia/Manila'))
            ->contains(fn ($report): bool => $report->sourceType === ManagementPlan::class && $report->sourceId === $plan->id))->toBeFalse()
        ->and($alerts->dueSoonReports(3, CarbonImmutable::parse('2026-09-08', 'Asia/Manila'))
            ->contains(fn ($report): bool => $report->sourceType === ManagementPlan::class && $report->sourceId === $plan->id))->toBeFalse();

    expect(fn () => app(SubmissionTrackingService::class)->assertMutable($plan->fresh()))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
