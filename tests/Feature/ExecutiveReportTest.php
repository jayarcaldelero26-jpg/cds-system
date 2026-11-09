<?php

use App\Models\ConservationReportSubmission;
use App\Models\ModuleDefinition;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Reports\ExecutiveReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Support/IsolatedExportStorage.php';

beforeEach(function (): void {
    isolateGeneratedExportStorage();
    $this->withoutVite();
    $this->seed(ModuleDefinitionSeeder::class);
});

afterEach(function (): void { removeIsolatedGeneratedExportStorage(); });

function executiveReportGlobalUser(): User
{
    $user = User::factory()->create(['section' => 'CDS', 'unit_assignment' => null]);
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
    return $user;
}

function executiveReportArea(User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'Executive Test PA', 'category' => 'National Park', 'municipality' => 'Test',
        'province' => 'Davao Oriental', 'region' => 'XI', 'status' => 'Active',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
}

test('reports exposes one scoped compliance summary without workbook details in the page', function (): void {
    $this->actingAs(executiveReportGlobalUser())
        ->get(route('reports.index', ['year' => 2026, 'domain' => 'engp', 'as_of_date' => '2026-10-31']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('report.filters.year', 2026)
            ->where('report.filters.domain', 'engp')
            ->where('report.filters.as_of_date', '2026-10-31')
            ->where('canExport', true)
            ->where('auth.canViewOfficeTat', true)
            ->has('report.summary.due')
            ->has('report.breakdowns.office')
            ->has('report.actions')
            ->has('report.details', 0));
});

test('period compliance counts each due requirement once using dated receipts through the cutoff', function (): void {
    $user = executiveReportGlobalUser();
    $area = executiveReportArea($user);
    $other = ProtectedArea::create([
        'name' => 'Outside Selected PA', 'category' => 'National Park', 'municipality' => 'Test',
        'province' => 'Davao Oriental', 'region' => 'XI', 'status' => 'Active',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    foreach ([
        [$area, 'Quarter 1', '2026-01-12', '2026-01-13', '2026-01-14'],
        [$area, 'Quarter 2', '2026-04-10', '2026-04-12', '2026-08-01'],
        [$area, 'Quarter 2', '2026-04-10', '2026-04-12', '2026-07-01'],
        [$area, 'Quarter 3', '2026-07-10', '2026-07-12', '2027-02-01'],
        [$other, 'Quarter 4', '2026-10-10', '2026-10-12', '2026-10-14'],
    ] as [$target, $period, $activity, $released, $received]) {
        ConservationReportSubmission::create([
            'workflow_key' => 'regular_pamb', 'protected_area_id' => $target->id, 'target_office' => 'CENRO Baganga',
            'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => $period,
            'date_accomplished' => $activity, 'date_conducted' => $activity,
            'date_report_released_cenro' => $released, 'date_received_penro' => $received,
        ]);
    }

    $this->actingAs($user);
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-10', 'Asia/Manila'));
    try {
        $service = app(ExecutiveReportService::class);
        $scope = [
            'year' => 2026, 'domain' => 'all', 'protected_area_id' => $area->id,
            'workflow' => 'regular_pamb', 'as_of_date' => '2027-01-31',
        ];
        $report = $service->report($scope, includeDetails: true);
        $byPeriod = collect($report['details'])->keyBy('period');
        expect($report['filters']['domain'])->toBe('pa')
            ->and($report['summary']['due'])->toBe(4)
            ->and($report['summary']['received'])->toBe(2)
            ->and($report['summary']['on_time'])->toBe(1)
            ->and($report['summary']['late'])->toBe(1)
            ->and($report['summary']['receipt_rate'])->toEqual(50)
            ->and($report['summary']['awaiting_receipt'])->toBe(1)
            ->and($report['summary']['missing'])->toBe(1)
            ->and($byPeriod->count())->toBe(4)
            ->and($byPeriod['Quarter 2']['receipt_date'])->toBe('2026-07-01')
            ->and($byPeriod['Quarter 3']['receipt_date'])->toBeNull()
            ->and($byPeriod['Quarter 3']['status'])->toBe('Awaiting PENRO receipt')
            ->and($byPeriod['Quarter 3']['action_office'])->toBe('PENRO Records')
            ->and($byPeriod['Quarter 4']['status'])->toBe('No submission record');

        $filtered = $service->report([...$scope, 'status' => 'Awaiting PENRO receipt'], includeDetails: true);
        expect($filtered['summary'])->toBe($report['summary'])
            ->and($filtered['breakdowns'])->toBe($report['breakdowns'])
            ->and($filtered['details'])->toHaveCount(1)
            ->and($filtered['actions'])->toHaveCount(1);

        $quarter = $service->report([...$scope, 'period' => 'Quarter 3']);
        expect($quarter['summary']['due'])->toBe(1)
            ->and($quarter['comparison']['period_label'])->toContain('Quarter 2')
            ->and($quarter['comparison']['received'])->toBe(1)
            ->and($quarter['comparison']['change_pp'])->toEqual(-100);

        $early = $service->report([...$scope, 'period' => 'Quarter 4', 'as_of_date' => '2026-10-01']);
        expect($early['summary']['due'])->toBe(0)
            ->and($early['summary']['not_due'])->toBe(1)
            ->and($early['summary']['receipt_rate'])->toBeNull();
    } finally {
        CarbonImmutable::setTestNow($previousNow);
    }
});

test('executive report validates the cutoff and keeps legacy routing status as a detail filter', function (): void {
    $this->actingAs(executiveReportGlobalUser());
    $service = app(ExecutiveReportService::class);
    $scope = ['year' => 2026, 'domain' => 'pa'];
    $unfiltered = $service->report($scope);
    $filtered = $service->report([...$scope, 'status' => 'Completed']);
    expect($filtered['filters']['status'])->toBe('Completed')
        ->and($filtered['summary'])->toBe($unfiltered['summary']);

    foreach (['2026-02-30', '2999-01-01'] as $cutoff) {
        expect(fn () => $service->normalizeFilters(['as_of_date' => $cutoff]))
            ->toThrow(ValidationException::class);
    }
});

test('executive exports carry the same applied scope and cutoff in their official outputs', function (): void {
    $user = executiveReportGlobalUser();
    $scope = ['year' => 2026, 'domain' => 'engp', 'as_of_date' => '2026-10-31', 'status' => 'Received on time'];
    $this->actingAs($user)->get(route('reports.index', $scope))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('report.filters.as_of_date', $scope['as_of_date'])
        ->where('report.filters.status', $scope['status']));

    $pdf = $this->get(route('reports.export', ['format' => 'pdf', ...$scope]));
    $pdf->assertOk()->assertHeader('Content-Disposition');
    expect($pdf->baseResponse->getContent())->toStartWith('%PDF');

    foreach (['xlsx' => 'xl/worksheets/sheet1.xml', 'docx' => 'word/document.xml'] as $format => $entry) {
        $response = $this->get(route('reports.export', ['format' => $format, ...$scope]));
        $response->assertOk()->assertHeader('Content-Disposition');
        $zip = new ZipArchive;
        expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
        try {
            $content = $zip->getFromName($entry);
            expect($content)->toContain($scope['as_of_date'])->toContain($scope['status']);
            if ($format === 'xlsx') {
                expect($zip->getFromName('xl/workbook.xml'))->toContain('Requirement Details')
                    ->and($zip->getFromName('xl/worksheets/sheet2.xml'))->toContain('PENRO receipt by cutoff');
            }
        } finally {
            $zip->close();
        }
    }
});

test('report viewers retain their existing report scope while export permission remains separate', function (): void {
    $viewer = User::factory()->create(['section' => 'CDS', 'unit_assignment' => null]);
    $viewer->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $this->actingAs($viewer)->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canExport', false)
        ->where('auth.canViewOfficeTat', false));
    $this->get(route('reports.export', ['format' => 'xlsx']))->assertForbidden();
});

test('retired and inactive definitions are absent from executive report requirements', function (): void {
    $definition = ModuleDefinition::query()->where('code', 'engp_cbep')->firstOrFail();
    $definition->update(['is_active' => false]);

    $this->actingAs(executiveReportGlobalUser());
    $report = app(ExecutiveReportService::class)->report(['year' => 2026, 'domain' => 'engp']);
    expect(collect($report['filter_options']['families'])->pluck('value')->all())->not->toContain('cds_lawin')
        ->and(collect($report['breakdowns']['family'])->pluck('label')->all())->not->toContain('Community-Based Employment Program (CBEP)')
        ->and(ModuleDefinition::query()->whereKey($definition->id)->exists())->toBeTrue();
});
