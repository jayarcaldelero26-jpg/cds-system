<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Dashboard\DashboardMonitoringService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00', 'Asia/Manila'));
    $this->user = User::factory()->create(['section' => 'CDS']);
    $this->normalPa = protectedArea('Pujada Bay Protected Landscape', $this->user);
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
    conservationReport($this->normalPa, $this->user, ['date_accomplished' => '2026-08-26']);
    engpReport($this->user, ['deadline_submission' => '2026-09-01']);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('summary.tracked_reports', 2)
            ->has('rows', 2)
            ->where('pagination.total', 2)
            ->has('filterOptions.programs', 3)
            ->where('filters.program', 'all'));
});

test('dashboard table presents only common conservation and ENGP fields', function () {
    $dashboardSource = file_get_contents(resource_path('js/Pages/Dashboard.jsx'));

    expect($dashboardSource)->toContain("label: 'Program / Module'")
        ->toContain("label: 'Office / Protected Area'")
        ->toContain("label: 'Reporting Period'")
        ->toContain("label: 'Deadline for Submission to PENRO'")
        ->toContain("label: 'Date Received by PENRO Records'")
        ->toContain("label: 'Number of Days Complied'")
        ->toContain("label: 'Timeliness'")
        ->toContain("label: 'Status of Submission'")
        ->toContain("label: 'MOV'")
        ->not->toContain("label: 'Date Conducted'")
        ->not->toContain("label: 'Date Accomplished'")
        ->not->toContain('Date of Report Released by CENRO Records')
        ->not->toContain("label: 'Date Endorsed to Regional Office'")
        ->not->toContain('Total Number of Days Delayed at the PENRO');
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
    return ConservationReportSubmission::create(array_merge([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $area->id,
        'target_office' => 'PENRO Davao Oriental',
        'activity_name' => 'Regular PAMB Meetings',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3',
        'date_conducted' => 'August 2026',
        'date_accomplished' => '2026-08-03',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $overrides));
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
        'period_key' => 'august',
        'period_label' => 'August 2026',
        'deadline_submission' => '2026-09-01',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $overrides));
}
