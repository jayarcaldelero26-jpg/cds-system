<?php

use App\Models\EngpReportSubmission;
use App\Models\User;
use App\Services\Dashboard\EngpDashboardMonitoringService;
use App\Services\Engp\EngpMonitoringStatusResolver;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 09:00:00', 'Asia/Manila'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function dashboardDevelopmentUser(array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'unit_assignment' => 'development',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ], $attributes));

    $user->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));

    return $user;
}

function dashboardGlobalUser(): User
{
    $role = Role::findOrCreate('Super Admin', 'web');
    $user = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $user->assignRole($role);
    return $user;
}

function dashboardEngpSubmission(array $overrides = []): EngpReportSubmission
{
    return EngpReportSubmission::create(array_merge([
        'workflow_key' => 'cbep',
        'office' => 'CENRO Baganga',
        'section_name' => 'NGP',
        'activity_name' => 'Community-Based Employment Program (CBEP)',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => '2026-08',
        'period_label' => 'August 2026',
        'deadline_submission' => '2026-08-20',
    ], $overrides));
}

test('ENGP KPI counts are based on active registry requirements and actual records', function (): void {
    $user = dashboardDevelopmentUser();
    dashboardEngpSubmission(['date_received_penro' => '2026-08-18']);

    $overview = $this->actingAs($user)->get(route('dashboard', [
        'view' => 'engp', 'year' => 2026, 'office' => 'CENRO Baganga', 'frequency' => 'monthly', 'period' => '2026-08',
    ]));

    $overview->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'engp')
        ->where('engp.summary.expected', 8)
        ->where('engp.summary.scheduled_requirements', 8)
        ->where('engp.summary.submitted', 1)
        ->where('engp.summary.reports_submitted', 1)
        ->where('engp.summary.pending', 0)
        ->where('engp.summary.overdue', 7)
        ->where('engp.summary.not_yet_submitted', 7)
        ->where('engp.summary.compliance_rate', 12.5));
});

test('ENGP separates overdue and pending requirements using the authoritative deadline', function (): void {
    $user = dashboardDevelopmentUser();
    dashboardEngpSubmission();

    $this->actingAs($user)->get(route('dashboard', [
        'view' => 'engp', 'year' => 2026, 'office' => 'CENRO Baganga', 'frequency' => 'monthly', 'period' => '2026-08',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('engp.summary.overdue', 8)
        ->where('engp.summary.pending', 0)
        ->where('engp.summary.not_yet_submitted', 8)
        ->where('engp.rows.0.status', 'Report Not Yet Submitted')
        ->where('engp.rows.0.record_source', 'Scheduled requirement')
        ->where('engp.rows.0.date_received', null));

    $this->actingAs($user)->get(route('dashboard', [
        'view' => 'engp', 'year' => 2026, 'office' => 'CENRO Baganga', 'frequency' => 'monthly', 'period' => '2026-09',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('engp.summary.overdue', 0)
        ->where('engp.summary.pending', 8)
        ->where('engp.summary.within_preparation_period', 8)
        ->where('engp.summary.ongoing_preparation', 0)
        ->where('engp.rows.0.status', 'Within Allowable Preparation Period'));
});

test('ENGP monitoring exposes matched submissions and the preparation window as distinct presentation statuses', function (): void {
    $user = dashboardDevelopmentUser();
    dashboardEngpSubmission([
        'period_key' => '2026-09',
        'period_label' => 'September 2026',
        'deadline_submission' => '2026-09-20',
        'date_received_penro' => '2026-09-17',
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 09:00:00', 'Asia/Manila'));

    $this->actingAs($user)->get(route('dashboard', [
        'view' => 'engp', 'year' => 2026, 'office' => 'CENRO Baganga', 'frequency' => 'monthly', 'period' => '2026-09',
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('engp.summary.scheduled_requirements', 8)
        ->where('engp.summary.reports_submitted', 1)
        ->where('engp.summary.within_preparation_period', 0)
        ->where('engp.summary.ongoing_preparation', 7)
        ->where('engp.summary.not_yet_submitted', 0)
        ->where('engp.rows', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => $row['monitoring_status'] === 'Report Submitted'
            && $row['record_source'] === 'Actual encoded submission'
            && $row['submission_matched'] === true
            && $row['date_received'] === '2026-09-17')));
});

test('ENGP office and frequency filters are applied to expected requirements', function (): void {
    $user = dashboardGlobalUser();

    $this->actingAs($user)->get(route('dashboard', ['view' => 'engp', 'frequency' => 'quarterly', 'office' => 'CENRO Mati']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engp.filterOptions.offices', ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon'])
            ->where('engp.summary.expected', 12)
            ->where('engp.officePerformance.0.office', 'CENRO Mati')
            ->where('engp.reportTypeCompliance.0.frequency', 'Quarterly'));
});

test('ENGP year and period filters produce an empty state without inventing records', function (): void {
    $user = dashboardDevelopmentUser();

    $this->actingAs($user)->get(route('dashboard', ['view' => 'engp', 'year' => 2026, 'period' => '2026-12', 'frequency' => 'monthly']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engp.summary.expected', 8)
            ->has('engp.rows', 8));

    $this->actingAs($user)->get(route('dashboard', ['view' => 'engp', 'year' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engp.summary.expected', 0)
            ->has('engp.rows', 0)
            ->where('engp.pagination.total', 0));
});

test('CENRO scope cannot see another CENRO through dashboard filters', function (): void {
    $user = dashboardDevelopmentUser(['office_designated' => 'CENRO Baganga']);
    dashboardEngpSubmission(['office' => 'CENRO Mati']);

    $this->actingAs($user)->get(route('dashboard', ['view' => 'engp', 'office' => 'CENRO Mati']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engp.summary.expected', 0)
            ->where('engp.filterOptions.offices', ['CENRO Baganga'])
            ->has('engp.rows', 0));
});

test('development users without technical report permission cannot receive ENGP dashboard data', function (): void {
    $user = User::factory()->create([
        'unit_assignment' => 'development',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    dashboardEngpSubmission(['date_received_penro' => '2026-08-18']);

    $this->actingAs($user)->get(route('dashboard', ['view' => 'engp']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('engp.summary.expected', 0)
            ->where('engp.summary.submitted', 0)
            ->has('engp.rows', 0)
            ->where('engp.pagination.total', 0));
});

test('PENRO and Super Admin users receive aggregate ENGP visibility', function (): void {
    $penro = dashboardDevelopmentUser(['section' => 'PENRO_CDS_CHIEF', 'office_designated' => 'PENRO Davao Oriental']);
    dashboardEngpSubmission(['office' => 'CENRO Baganga']);
    dashboardEngpSubmission(['office' => 'CENRO Mati', 'period_key' => '2026-09', 'period_label' => 'September 2026', 'deadline_submission' => '2026-09-20', 'date_received_penro' => '2026-08-20']);

    $this->actingAs($penro)->get(route('dashboard', ['view' => 'engp', 'frequency' => 'monthly', 'period' => '2026-09']))
        ->assertInertia(fn (Assert $page) => $page->where('engp.filterOptions.offices.0', 'CENRO Baganga')->where('engp.filterOptions.offices.2', 'CENRO Mati')->where('engp.summary.submitted', 1));

    $admin = dashboardGlobalUser();
    $this->actingAs($admin)->get(route('dashboard', ['view' => 'engp', 'frequency' => 'quarterly']))
        ->assertInertia(fn (Assert $page) => $page->where('engp.filterOptions.offices', ['CENRO Baganga', 'CENRO Manay', 'CENRO Mati', 'CENRO Lupon']));
});

test('PAMO and conservation users do not gain ENGP visibility from filters', function (): void {
    $pamo = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'PAMO']);
    dashboardEngpSubmission(['office' => 'CENRO Mati']);

    $this->actingAs($pamo)->get(route('dashboard', ['view' => 'engp']))
        ->assertInertia(fn (Assert $page) => $page->where('engp.summary.expected', 0)->has('engp.rows', 0));
});

test('legacy combined view is safely redirected to PA monitoring', function (): void {
    $admin = dashboardGlobalUser();
    dashboardEngpSubmission(['date_received_penro' => '2026-08-18']);

    $this->actingAs($admin)->get(route('dashboard', ['view' => 'combined', 'year' => 2026]))
        ->assertRedirect(route('dashboard', ['view' => 'pa']));
});

test('PA dashboard remains available and keeps the existing monitoring contract', function (): void {
    $user = User::factory()->create(['section' => 'CDS']);

    $this->actingAs($user)->get(route('dashboard', ['view' => 'pa']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('view', 'pa')
            ->has('summary')
            ->has('rows')
            ->has('filterOptions.programs'));
});

test('ENGP monitoring status boundary is characterized separately from submitted state', function (): void {
    $resolver = app(EngpMonitoringStatusResolver::class);
    $today = CarbonImmutable::parse('2026-09-01', 'Asia/Manila');
    $originalWindow = config('notifications.due_soon_days');

    try {
        config(['notifications.due_soon_days' => 3]);

        expect($resolver->resolve('2026-09-10', null, $today)['key'])->toBe('within_preparation')
            ->and($resolver->resolve('2026-09-04', null, $today)['key'])->toBe('ongoing_preparation')
            ->and($resolver->resolve('2026-09-02', null, $today)['key'])->toBe('ongoing_preparation')
            ->and($resolver->resolve('2026-09-01', null, $today)['key'])->toBe('ongoing_preparation')
            ->and($resolver->resolve('2026-08-31', null, $today)['key'])->toBe('not_yet_submitted')
            ->and($resolver->resolve('2026-09-10', '2026-08-20', $today)['key'])->toBe('submitted')
            ->and($resolver->resolve('2026-09-01', '2026-09-02', $today)['key'])->toBe('submitted');

        config(['notifications.due_soon_days' => 5]);
        expect($resolver->resolve('2026-09-05', null, $today)['key'])->toBe('ongoing_preparation');
    } finally {
        config(['notifications.due_soon_days' => $originalWindow]);
    }
});
