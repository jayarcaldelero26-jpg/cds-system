<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Dashboard\DashboardMonitoringService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00', 'Asia/Manila'));
    $this->user = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'CENRO Mati']);
    $this->normalPa = protectedArea('Pujada Bay Protected Landscape', $this->user, 'PBPLS');
    $this->mhrws = protectedArea('Mt. Hamiguitan Range Wildlife Sanctuary', $this->user, 'MHRWS');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('dashboard aggregates live conservation and ENGP records without dashboard persistence', function () {
    $mhrwsReport = conservationReport($this->mhrws, $this->user, ['date_accomplished' => '2026-08-03']);
    $engp = engpReport($this->user, ['date_received_penro' => '2026-08-18', 'deadline_submission' => '2026-08-20']);

    $overview = app(DashboardMonitoringService::class)->overview(['year' => 2026]);
    $mhrwsRow = collect($overview['rows'])->firstWhere('id', 'conservation-'.$mhrwsReport->id);
    $engpRow = collect($overview['rows'])->firstWhere('id', 'engp-'.$engp->id);

    expect($overview['summary']['tracked_reports'])->toBe(2)
        ->and($mhrwsRow['program'])->toBe('Conservation / Protected Area')
        ->and($mhrwsRow['cenro_release_applicable'])->toBeFalse()
        ->and($engpRow['program'])->toBe('ENGP')
        ->and($engpRow['date_accomplished'])->toBeNull()
        ->and($engpRow['date_endorsed_regional'])->toBeNull()
        ->and($engpRow['days_complied'])->toBe(2)
        ->and($engpRow['timeliness'])->toBe('Very Satisfactory')
        ->and(ConservationReportSubmission::count())->toBe(1)
        ->and(EngpReportSubmission::count())->toBe(1);
});

test('dashboard overdue state follows live PENRO receipt state', function () {
    $report = conservationReport($this->normalPa, $this->user, ['date_accomplished' => '2026-08-03']);
    $dashboard = app(DashboardMonitoringService::class);

    expect($dashboard->overview(['year' => 2026])['summary']['overdue'])->toBe(1);

    $report->update(['date_received_penro' => '2026-08-20']);
    $refreshed = $dashboard->overview(['year' => 2026]);

    expect($refreshed['summary']['overdue'])->toBe(0)
        ->and($refreshed['summary']['submitted'])->toBe(1)
        ->and(ConservationReportSubmission::count())->toBe(1);
});

test('dashboard filters sources and combines PA and ENGP monitoring records', function () {
    conservationReport($this->normalPa, $this->user, ['date_accomplished' => '2026-08-26']);
    $engp = engpReport($this->user, ['deadline_submission' => '2026-09-01']);
    $dashboard = app(DashboardMonitoringService::class);

    $all = $dashboard->overview(['year' => 2026]);
    $engpOnly = $dashboard->overview(['year' => 2026, 'program' => 'engp']);

    expect(collect($all['upcomingDeadlines'])->pluck('source')->all())->toContain('conservation', 'engp')
        ->and($engpOnly['summary']['tracked_reports'])->toBe(1)
        ->and($engpOnly['rows'][0]['id'])->toBe('engp-'.$engp->id);
});

test('dashboard page exposes the unified monitoring props', function () {
    $report = conservationReport($this->normalPa, $this->user, ['date_accomplished' => '2026-08-26']);
    engpReport($this->user, ['deadline_submission' => '2026-09-01']);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('view', 'all')
            ->where('dashboard.tab', 'conservation')
            ->has('dashboard.programs', 2)
            ->has('dashboard.filterOptions.years')
            ->missing('dashboard.filterOptions.programs')
            ->has('dashboard.trackingRows')
            ->where('dashboard.trackingTotal', 1)
            ->where('dashboard.trackingRows.0.id', 'conservation-'.$report->id)
            ->where('dashboard.filters.program', 'pa'));
});

test('PA Monitoring excludes CBEP and every ENGP registry workflow before aggregation', function (): void {
    $pa = conservationReport($this->normalPa, $this->user, ['date_accomplished' => '2026-08-26']);
    foreach (app(EngpReportWorkflowRegistry::class)->keys() as $index => $workflowKey) {
        EngpReportSubmission::create([
            'workflow_key' => $workflowKey,
            'office' => 'CENRO Baganga',
            'activity_name' => $workflowKey === 'cbep' ? 'Community-Based Employment Program (CBEP)' : $workflowKey,
            'document_type' => 'Report',
            'reporting_year' => 2026,
            'period_key' => '2026-'.str_pad((string) (($index % 9) + 1), 2, '0', STR_PAD_LEFT),
            'period_label' => 'Period '.($index + 1),
            'deadline_submission' => '2026-09-30',
        ]);
    }

    $this->actingAs($this->user)->get(route('dashboard', ['view' => 'pa', 'program' => 'engp', 'report_type' => 'CBEP']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.tracked_reports', 0)
            ->has('rows', 0)
            ->where('filters.program', 'conservation')
            ->has('paMatrix', 0));
});

test('ENGP monitoring excludes PA workflows and legacy combined view falls back to PA', function (): void {
    conservationReport($this->normalPa, $this->user, ['date_accomplished' => '2026-08-26']);
    $engp = engpReport($this->user, ['date_received_penro' => '2026-08-18']);
    $admin = dashboardGlobalUser();

    $this->actingAs($admin)->get(route('dashboard', ['view' => 'engp', 'year' => 2026, 'office' => 'CENRO Mati', 'frequency' => 'monthly', 'period' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engp.rows.0.source', 'engp')
            ->where('engp.summary.expected', 8));

    $this->actingAs($admin)->get(route('dashboard', ['view' => 'combined', 'year' => 2026]))
        ->assertRedirect(route('dashboard', ['view' => 'pa']));

    expect($engp->exists)->toBeTrue();
});

test('PA filters and authorization are applied before PA aggregation', function (): void {
    $cenro = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    conservationReport($this->normalPa, $this->user, ['protected_area_id' => null, 'target_office' => 'CENRO Baganga', 'date_accomplished' => '2026-08-26']);
    conservationReport($this->normalPa, $this->user, ['protected_area_id' => null, 'target_office' => 'CENRO Mati', 'date_accomplished' => '2026-08-26']);

    $this->actingAs($cenro)->get(route('dashboard', ['view' => 'pa', 'office' => 'CENRO Mati']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.tracked_reports', 0)
            ->where('filterOptions.offices', ['CENRO Baganga'])
            ->has('rows', 0));
});

test('empty PA dashboard returns compact management empty states', function (): void {
    $admin = dashboardGlobalUser();

    $this->actingAs($admin)->get(route('dashboard', ['view' => 'pa', 'year' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.tracked_reports', 0)
            ->where('summary.submitted', 0)
            ->where('summary.pending', 0)
            ->where('summary.overdue', 0)
            ->has('paMatrix', 0)
            ->has('topOverdueReports', 0)
            ->where('executiveInterpretation.0.text', 'No tracked PA report records match the selected filters.'));
});

test('report submission overview presents the required tab and chart surfaces', function () {
    $dashboardSource = file_get_contents(resource_path('js/Pages/Dashboard.jsx'));

    expect($dashboardSource)->toContain('Report Submission Overview')
        ->toContain("import monitoringMountainForest from '../../images/dashboard/monitoring-mountain-forest.png'")
        ->toContain('url(${monitoringMountainForest})')
        ->not->toContain('dashboard-terrain-sky')
        ->not->toContain('<svg')
        ->not->toContain("SUPERVISOR'S GUIDE")
        ->toContain('Status as of {formatReportDate(dashboard.as_of)}')
        ->not->toContain('formatReportDateTime')
        ->toContain('Conservation submissions from PAMOs')
        ->toContain('Development submissions from CENROs')
        ->toContain('Reporting Year')
        ->toContain('DEVELOPMENT REPORTS')
        ->toContain('CONSERVATION REPORTS')
        ->toContain('<Metric label="Pending"')
        ->toContain('<Metric label="In Progress"')
        ->toContain('<Metric label="Overdue"')
        ->toContain('<Metric label="On Time"')
        ->toContain('<Metric label="Average Early"')
        ->toContain('<Metric label="Average Late"')
        ->toContain('metrics.pending')
        ->toContain('metrics.in_progress')
        ->toContain('metrics.overdue')
        ->toContain('metrics.on_time_rate')
        ->toContain('metrics.average_early')
        ->toContain('metrics.average_late')
        ->toContain('<Program program={program} tab={tab} />')
        ->toContain("view === 'engp' ? <DevelopmentDashboard data={engp} />")
        ->toContain('Scheduled Requirements')
        ->toContain('Pending Preparation')
        ->toContain('Ongoing Preparation')
        ->toContain('Compliance Rate')
        ->toContain('All Development Requirements')
        ->toContain('Conservation')
        ->toContain('Development')
        ->not->toContain("field('Program'")
        ->toContain('Pending and Ongoing Submissions')
        ->toContain('Overdue and Still Unreceived')
        ->toContain('Submission Comparison')
        ->toContain('Timeliness Summary')
        ->toContain('All {tab === \'development\' ? \'Development\' : \'Conservation\'} Report Submissions')
        ->toContain('How Days Are Counted')
        ->toContain('source_url')
        ->toContain('BarChart')
        ->toContain('YAxis domain={[0, 100]}');
});

test('dashboard tabs map to canonical PA and ENGP projections and preserve filters', function (): void {
    $admin = dashboardGlobalUser();
    engpReport($this->user, ['deadline_submission' => '2026-09-01']);
    $filters = ['year' => 2026, 'program' => 'engp', 'frequency' => 'Monthly'];
    $this->actingAs($admin);
    $expected = app(DashboardMonitoringService::class)->submissionOverview($filters);

    $this->get(route('dashboard', ['tab' => 'development', 'year' => 2026, 'frequency' => 'Monthly']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.tab', 'development')
            ->where('dashboard.filters.program', 'engp')
            ->where('dashboard.filters.year', 2026)
            ->where('dashboard.filters.frequency', 'Monthly')
            ->where('dashboard.programs.0.key', 'engp')
            ->where('dashboard.programs.0.metrics.pending', $expected['programs'][0]['metrics']['pending'])
            ->where('dashboard.trackingTotal', $expected['trackingTotal'])
            ->missing('engp'));

    $this->actingAs($admin)->get(route('dashboard', ['tab' => 'invalid', 'year' => 2026]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.tab', 'conservation')
            ->where('dashboard.filters.program', 'pa'));
});

test('development office options come from authorized active CENRO master data without reports', function (): void {
    $admin = dashboardGlobalUser();

    $this->actingAs($admin)->get(route('dashboard', ['tab' => 'development', 'year' => 2026]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.program', 'engp')
            ->where('dashboard.filterOptions.offices', ['CENRO Baganga', 'CENRO Lupon', 'CENRO Manay', 'CENRO Mati'])
            ->where('dashboard.programs.0.key', 'engp')
            ->where('dashboard.programs.0.office_count', 4)
            ->where('dashboard.programs.0.metrics.pending', 0)
            ->where('dashboard.programs.0.metrics.in_progress', 0)
            ->where('dashboard.programs.0.metrics.overdue', 0));

    expect(OrganizationalOffice::query()->where('office_type', 'cenro')->where('is_active', true)->count())->toBe(4);
});

test('dashboard normalization safely excludes malformed deadlines while preserving descriptive date conducted text', function () {
    $service = app(DashboardMonitoringService::class);
    $present = new ReflectionMethod($service, 'present');
    $row = $present->invoke($service, [
        'source' => 'conservation', 'source_id' => 1, 'module' => 'Regular PAMB Meetings', 'target_office' => 'CENRO Mati',
        'reporting_year' => 2026, 'reporting_period' => 'August 2026', 'date_conducted' => 'Aug. 3-4, 2026',
        'deadline_submission' => 'not-a-date', 'date_accomplished' => 'bad-date', 'date_report_released_cenro' => '',
        'date_received_penro' => null, 'date_endorsed_regional' => '—', 'release_events' => [], 'stage' => 'cenro_release',
    ], CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));

    expect($row['deadline_submission'])->toBeNull()
        ->and($row['date_report_released_cenro'])->toBeNull()
        ->and($row['date_accomplished'])->toBeNull()
        ->and($row['date_endorsed_regional'])->toBeNull()
        ->and($row['date_conducted'])->toBe('Aug. 3-4, 2026');
});

test('dashboard hides a protected-area alias but keeps distinct offices in the combined display', function () {
    $service = app(DashboardMonitoringService::class);
    $present = new ReflectionMethod($service, 'present');
    $base = [
        'source' => 'conservation', 'source_id' => 1, 'module' => 'Report', 'protected_area' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)',
        'reporting_period' => 'Quarter 1', 'deadline_submission' => '2026-08-29', 'date_accomplished' => '2026-08-20',
        'date_received_penro' => null, 'timeliness' => null, 'stage' => 'cenro_release',
    ];

    $mhrws = $present->invoke($service, [...$base, 'target_office' => 'Hamiguitan'], CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));
    $normal = $present->invoke($service, [...$base, 'source_id' => 2, 'target_office' => 'CENRO Mati', 'protected_area' => 'Pujada Bay Protected Landscape'], CarbonImmutable::parse('2026-08-29', 'Asia/Manila'));

    expect($mhrws['office_or_pa'])->toBe('Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)')
        ->and($normal['office_or_pa'])->toBe("CENRO Mati \u{2014} Pujada Bay Protected Landscape");
});

function protectedArea(string $name, User $user, ?string $shortName = null): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name,
        'short_name' => $shortName,
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

function conservationReport(ProtectedArea $area, User $user, array $overrides = []): ConservationReportSubmission
{
    $data = array_merge([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $area->id,
        'target_office' => $area->short_name === 'MHRWS' ? 'PENRO Davao Oriental' : 'CENRO Mati',
        'activity_name' => 'Regular PAMB Meetings',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $overrides);
    if (array_key_exists('date_accomplished', $overrides) && ! array_key_exists('date_conducted', $overrides)) {
        $data['date_conducted'] = $data['date_accomplished'];
    }

    return ConservationReportSubmission::create($data);
}

function engpReport(User $user, array $overrides = []): EngpReportSubmission
{
    return EngpReportSubmission::create(array_merge([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Mati',
        'section_name' => 'NGP',
        'activity_name' => 'Community-Based Employment Program (CBEP)',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => '2026-08',
        'period_label' => 'August 2026',
        'deadline_submission' => '2026-09-01',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $overrides));
}
