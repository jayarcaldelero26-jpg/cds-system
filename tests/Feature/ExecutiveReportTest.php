<?php

use App\Models\ModuleDefinition;
use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use Database\Seeders\ModuleDefinitionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
});

function executiveReportGlobalUser(): User
{
    $user = User::factory()->create(['section' => 'CDS', 'unit_assignment' => null]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('reports.export', 'web'));
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
    return $user;
}

test('reports is a management workspace backed by one normalized executive payload', function (): void {
    $response = $this->actingAs(executiveReportGlobalUser())->get(route('reports.index', ['year' => 2027, 'domain' => 'engp']));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index')
        ->where('report.filters.year', 2027)
        ->where('report.filters.domain', 'engp')
        ->has('report.summary.expected')
        ->has('report.timeliness')
        ->has('report.family_performance')
        ->has('report.attention'));
});

test('executive metrics reconcile canonical expected PA requirements with actual tracked submissions', function (): void {
    $user = executiveReportGlobalUser();
    $area = ProtectedArea::create(['name' => 'Executive Test PA', 'category' => 'National Park', 'municipality' => 'Test', 'province' => 'Davao Oriental', 'region' => 'XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);
    ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2027-01-15', 'date_report_released_cenro' => '2027-01-20',
        'date_received_penro' => '2027-01-22', 'date_endorsed_regional' => '2027-01-23',
    ]);
    ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-01-15', 'date_report_released_cenro' => '2026-01-20',
        'date_received_penro' => '2026-01-22', 'date_endorsed_regional' => '2026-01-23',
    ]);

    $this->actingAs($user);
    $report = app(\App\Services\Reports\ExecutiveReportService::class)->report(['year' => 2027, 'domain' => 'pa']);
    $pambFamily = collect($report['family_performance'])->firstWhere('label', 'Regular PAMB Meetings');

    expect($report['summary']['expected'])->toBeGreaterThan(0)
        ->and($report['summary']['submitted'])->toBe(1)
        ->and($pambFamily['submitted'] ?? null)->toBe(1)
        ->and(collect($report['family_performance'])->pluck('label')->all())->toContain('Regular PAMB Meetings');
});

test('executive report accepts dynamic filters and excludes retired definitions', function (): void {
    $report = app(\App\Services\Reports\ExecutiveReportService::class)->report(['year' => 2028, 'domain' => 'engp']);

    expect(collect($report['filter_options']['families'])->pluck('value')->all())->not->toContain('cds_lawin')
        ->and($report['filters']['year'])->toBe(2028);
});

test('executive status filters use canonical routing labels without counting unmatched obligations', function (): void {
    $user = executiveReportGlobalUser();
    $area = ProtectedArea::create(['name' => 'Executive Status PA', 'category' => 'National Park', 'municipality' => 'Test', 'province' => 'Davao Oriental', 'region' => 'XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $rows = [
        ['Quarter 1', '2027-01-10', '2027-01-12', '2027-01-13', '2027-01-14', 'Completed'],
        ['Quarter 2', '2027-04-10', '2027-04-12', '2027-04-13', null, 'Pending Regional Endorsement'],
        ['Quarter 3', '2027-07-10', '2027-07-12', null, null, 'Pending Receipt by PENRO'],
        ['Quarter 4', '2027-10-10', null, null, null, 'Pending Submission by CENRO'],
    ];

    foreach ($rows as [$period, $activity, $released, $received, $endorsed]) {
        ConservationReportSubmission::create([
            'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
            'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => $period,
            'date_accomplished' => $activity, 'date_conducted' => $activity,
            'date_report_released_cenro' => $released, 'date_received_penro' => $received,
            'date_endorsed_regional' => $endorsed,
        ]);
    }

    $this->actingAs($user);
    $service = app(\App\Services\Reports\ExecutiveReportService::class);
    foreach (array_column($rows, 5) as $status) {
        $report = $service->report(['year' => 2027, 'domain' => 'pa', 'protected_area_id' => $area->id, 'workflow' => 'regular_pamb', 'status' => $status]);

        expect($report['filters']['status'])->toBe($status)
            ->and(collect($report['filter_options']['statuses'])->all())->toContain($status)
            ->and($report['summary']['expected'])->toBe(1)
            ->and($report['summary']['submitted'])->toBe(in_array($status, ['Completed', 'Pending Regional Endorsement'], true) ? 1 : 0)
            ->and($report['summary']['overdue'])->toBe(0)
            ->and($report['timeliness']['rated'])->toBe(1);
    }

    $noActivity = $service->report(['year' => 2027, 'domain' => 'pa', 'protected_area_id' => $area->id, 'workflow' => 'regular_pamb', 'status' => 'No Activity Conducted']);
    expect($noActivity['summary']['expected'])->toBe(0)
        ->and($noActivity['summary']['submitted'])->toBe(0)
        ->and($noActivity['summary']['overdue'])->toBe(0)
        ->and($noActivity['timeliness']['rated'])->toBe(0);

    expect(fn () => $service->report(['year' => 2027, 'domain' => 'pa', 'status' => 'Overdue']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('executive PDF, XLSX, and DOCX exports use the report export routes', function (): void {
    $user = executiveReportGlobalUser();
    foreach (['pdf', 'xlsx', 'docx'] as $format) {
        $response = $this->actingAs($user)->get(route('reports.export', ['format' => $format, 'year' => 2027]));
        $response->assertOk()->assertHeader('Content-Disposition');
    }
});

test('inactive definitions are absent from executive expected requirements without deleting the definition', function (): void {
    $definition = ModuleDefinition::query()->where('code', 'engp_cbep')->firstOrFail();
    $definition->update(['is_active' => false]);

    $report = app(\App\Services\Reports\ExecutiveReportService::class)->report(['year' => 2027, 'domain' => 'engp']);

    expect(collect($report['family_performance'])->pluck('label')->all())->not->toContain('Community-Based Employment Program (CBEP)')
        ->and(ModuleDefinition::query()->whereKey($definition->id)->exists())->toBeTrue();
});
