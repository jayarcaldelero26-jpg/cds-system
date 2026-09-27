<?php

use App\Models\User;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ProtectedArea;
use App\Services\Dashboard\DashboardMonitoringService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

test('the welcome page is public and exposes only safe overview aggregates', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('overview', 6)
            ->has('publicSummary.totals', 5)
            ->has('publicSummary.programs', 2)
            ->has('publicSummary.as_of')
            ->missing('overview.rows')
            ->missing('overview.users')
            ->missing('overview.attachments'));
});

test('public landing bundle preserves summary and trend values while loading current-year records once', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 10:00:00', 'Asia/Manila'));
    $user = User::factory()->create(['section' => 'CDS']);
    $area = ProtectedArea::create([
        'name' => 'Public Landing Performance PA', 'short_name' => 'PLP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Public landing meeting', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-02-03', 'date_accomplished' => '2026-02-03', 'deadline_submission' => '2026-02-28',
        'date_received_penro' => '2026-03-02', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    EngpReportSubmission::create([
        'workflow_key' => 'cbep', 'office' => 'CENRO Mati', 'activity_name' => 'Public landing ENGP', 'document_type' => 'Monthly Report',
        'reporting_year' => 2026, 'period_key' => '2026-02', 'period_label' => 'February 2026',
        'deadline_submission' => '2026-02-28', 'date_received_penro' => '2026-02-27',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void { $queries[] = strtolower($query->sql); });
    $response = $this->get('/')->assertOk();
    $payload = $response->viewData('page')['props'];
    $conservationFullReads = collect($queries)->filter(fn (string $sql): bool => str_starts_with(trim($sql), 'select * from "conservation_report_submissions"'))->count();
    $engpFullReads = collect($queries)->filter(fn (string $sql): bool => str_starts_with(trim($sql), 'select * from "engp_report_submissions"'))->count();

    $service = app(DashboardMonitoringService::class);
    $legacyOverview = $service->overview([], false);
    $expectedOverview = [
        'tracked_reports' => $legacyOverview['summary']['tracked_reports'],
        'submitted' => $legacyOverview['summary']['submitted'],
        'overdue' => $legacyOverview['summary']['overdue'],
        'reports_due' => $legacyOverview['summary']['reports_due'],
        'compliant' => $legacyOverview['summary']['compliant'],
        'monitoring_sources' => collect($legacyOverview['rows'])->pluck('source')->filter()->unique()->count(),
    ];

    expect($payload['overview'])->toBe($expectedOverview)
        ->and($payload['publicSummary'])->toBe($service->publicSummary())
        ->and($payload['reportTrend'])->toBe($service->publicSubmissionTrend())
        ->and(collect($payload['reportTrend'])->firstWhere('label', 'Mar 26')['count'])->toBe(1)
        ->and($conservationFullReads)->toBe(1)
        ->and($engpFullReads)->toBe(1);
    CarbonImmutable::setTestNow();
});

test('welcome page source uses CDS-SMART branding and the existing login route', function () {
    $welcome = File::get(resource_path('js/Pages/Welcome.jsx'));

    expect($welcome)
        ->toContain('CDS-SMART')
        ->toContain('PENRO Davao Oriental')
        ->toContain('Access Login')
        ->toContain('href="/login"')
        ->not->toContain('Secure')
        ->not->toContain('Confidential')
        ->not->toContain('Reliable')
        ->not->toMatch('/CDSIMS|CDS-IMS|CDS IMS|CDS System|CDIMS|Conservation and Development Information Management System/i');

    expect(route('login', absolute: false))->toBe('/login');
});

test('welcome page follows the public report-status composition and excludes the retired capability layout', function () {
    $welcome = File::get(resource_path('js/Pages/Welcome.jsx'));

    expect($welcome)
        ->toContain('PENRO Report Submission Status')
        ->toContain('Submission status by program')
        ->toContain('On-time rate')
        ->toContain('How to read the status')
        ->toContain('>Access Login <Icon')
        ->toContain('publicSummary')
        ->not->toContain('Report status')
        ->not->toContain('Access login')
        ->not->toContain('<nav')
        ->not->toContain('System capabilities')
        ->not->toContain('Overall Report Trend')
        ->not->toContain('function OverviewTrend');
});

test('login and authenticated layouts follow the CDS-SMART and DENR branding rules', function () {
    $login = File::get(resource_path('js/Layouts/AuthLayout.jsx'));
    $authenticated = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($login)
        ->toContain('CDS-SMART')
        ->toContain('Submission Monitoring and Reminder Tool')
        ->toContain('PENRO Davao Oriental');

    expect($authenticated)
        ->toContain('const logoSrc = "/images/DENR LOGO.png"')
        ->toContain('CDS-SMART')
        ->toContain('Submission Monitoring and Reminder Tool')
        ->toContain('Conservation and Development Section')
        ->not->toContain('eDATS-ENGP')
        ->not->toContain('CDS Logo.png')
        ->not->toMatch('/CDSIMS|CDS-IMS|CDS IMS|CDS System|CDIMS/i');
});

test('login presentation keeps accessible icon input and theme controls', function () {
    $layout = File::get(resource_path('js/Layouts/AuthLayout.jsx'));
    $login = File::get(resource_path('js/Pages/Auth/Login.jsx'));
    $authField = File::get(resource_path('js/Components/AuthField.jsx'));
    $flashDialog = File::get(resource_path('js/Components/FlashSuccessDialog.jsx'));

    expect($layout)
        ->toContain('ThemeIcon')
        ->toContain("/images/cds-smart-background.png")
        ->toContain('from-emerald-950/55')
        ->toContain('BrandDivider');

    expect(File::get(resource_path('js/Pages/Welcome.jsx')))
        ->toContain("/images/cds-smart-background.png");

    expect($login)
        ->toContain('autoComplete="email"')
        ->toContain('icon="mail"')
        ->toContain('icon="lock"')

        ->toContain('type="button"')
        ->toContain("showPassword ? 'text' : 'password'")
        ->toContain("showPassword ? 'Hide password' : 'Show password'")
        ->toContain('Forgot password?')
        ->toContain('Need an account?')
        ->toContain('Account Pending Approval')
        ->toContain('pendingApproval')
        ->not->toContain("{showPassword ? 'Hide' : 'Show'}")
        ->not->toContain('flex items-center rounded-xl border bg-white');

    expect($authField)
        ->toContain('leadingIcon')
        ->toContain('absolute right-2 top-6 z-20 flex h-11 items-center');

    expect($flashDialog)
        ->toContain('Registration Request Submitted')
        ->toContain('registration_success');
});

test('ENGP remains a program within the authenticated CDS-SMART navigation', function () {
    $authenticated = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($authenticated)
        ->toContain("label: 'National Greening Program'")
        ->toContain("label: 'ENGP IAC Generator'")
        ->toContain("label: 'Report Submission Monitoring'")
        ->not->toContain("label: 'ENGP Dashboard'")
        ->not->toContain("label: 'OPERATIONS'");
});

test('the existing authentication entry points remain available', function () {
    $user = User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
});
