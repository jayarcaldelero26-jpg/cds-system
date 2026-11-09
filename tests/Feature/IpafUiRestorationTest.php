<?php

use App\Models\IpafAccountingStatus;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\IpafRevenueTarget;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'PENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'PENRO Davao Oriental']);
    foreach (['technical-reports.view', 'technical-reports.update'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

test('IPAF index supplies core revenue analysis, annual target, and accounting data sections', function () {
    $this->actingAs($this->user)->get(route('ipaf.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ipaf/Index')
            ->has('quarterlyRevenueSummary')
            ->has('annualRevenuePerformance')
            ->has('accountingStatusSummary')
            ->has('ipafAnalysis')
            ->has('ipafAnalysis.monthly_revenue'));

    $page = File::get(resource_path('js/Pages/Ipaf/Index.jsx'));
    expect($page)->toContain('Quarterly Performance')
        ->and($page)->toContain('Accounting Section')
        ->and($page)->toContain('IPAF Analysis')
        ->and($page)->toContain('RevenueQuarterlySummary')
        ->and($page)->toContain('AccountingSection')
        ->and($page)->toContain('IpafAnalysis');
});

test('IPAF financial and management views use separate PageHeader/module compositions', function () {
    $page = File::get(resource_path('js/Pages/Ipaf/Index.jsx'));

    expect($page)->toContain('PageHeader title="Revenue Collection"')
        ->and($page)->toContain('PageHeader title="Management of IPAF"')
        ->and($page)->not->toContain("{ key: 'management', label: 'Management of IPAF Report' }");

    $this->actingAs($this->user)->get(route('ipaf.index', ['ipaf_tab' => 'management']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Ipaf/Index'));
});

test('IPAF management persists the activity name and keeps it when a legacy edit omits it', function (): void {
    Storage::fake('local');
    $this->user->givePermissionTo(Permission::findOrCreate('technical-reports.create', 'web'));
    $area = ProtectedArea::create([
        'name' => 'IPAF Management Test Area', 'short_name' => 'MHRWS', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $payload = [
        'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental', 'document_type' => 'Final Report',
        'date_conducted' => '2026-10-01', 'date_accomplished' => '2026-10-03',
    ];
    $activity = 'Protected area fund reconciliation workshop';
    $this->actingAs($this->user)->post(route('ipaf.management.store'), [
        ...$payload,
        'activity_name' => $activity,
        'mov' => UploadedFile::fake()->createWithContent('ipaf-report.pdf', "%PDF-1.4\nIPAF fixture\n%%EOF"),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $report = IpafManagementReport::query()->sole();
    expect($report->activity_name)->toBe($activity);

    $update = route('ipaf.management.update', $report);
    $this->put($update, $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->activity_name)->toBe($activity);
    $this->put($update, [...$payload, 'activity_name' => str_repeat('a', 256)])->assertSessionHasErrors('activity_name');
    expect($report->fresh()->activity_name)->toBe($activity);
    $this->put($update, [...$payload, 'activity_name' => 'Revised IPAF activity'])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->activity_name)->toBe('Revised IPAF activity');
});

test('live revenue derives the 25th from the reporting month and preserves an existing deadline for the same period', function (): void {
    Storage::fake('local');
    $this->user->givePermissionTo(Permission::findOrCreate('technical-reports.create', 'web'));
    $area = ProtectedArea::create([
        'name' => 'IPAF Revenue Test Area', 'short_name' => 'MHRWS', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $payload = [
        'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental', 'document_type' => 'Final Report',
        'reporting_year' => 2026, 'reporting_month' => 10, 'total_collected' => '125.00',
    ];
    $this->actingAs($this->user)->post(route('ipaf.revenue.store'), [
        ...$payload, 'mov' => UploadedFile::fake()->createWithContent('revenue-report.pdf', "%PDF-1.4\nRevenue fixture\n%%EOF"),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $report = IpafRevenueCollection::query()->sole();
    expect($report->deadline_submission->toDateString())->toBe('2026-10-25')
        ->and($report->ipaf_ria)->toBe('93.75')
        ->and($report->sagf)->toBe('31.25');

    $report->update(['deadline_submission' => '2026-10-20']);
    $update = route('ipaf.revenue.update', $report);
    $this->put($update, [...$payload, 'deadline_submission' => '2026-11-20'])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->deadline_submission->toDateString())->toBe('2026-10-20');

    $this->put($update, [...$payload, 'reporting_month' => 12, 'deadline_submission' => '2027-01-20'])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->deadline_submission->toDateString())->toBe('2026-12-25');
});

test('existing IPAF annual targets and bank account information remain persisted through their existing endpoints', function () {
    $area = ProtectedArea::create([
        'name' => 'IPAF Test Protected Area', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)->put(route('ipaf.revenue-targets.update'), [
        'protected_area_id' => $area->id, 'reporting_year' => 2026,
        'targets' => ['1' => '100.00', '2' => '200.00', '3' => null, '4' => null],
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('ipaf_revenue_targets', ['protected_area_id' => $area->id, 'reporting_year' => 2026, 'quarter' => 1, 'target_amount' => '100.00']);

    $this->actingAs($this->user)->put(route('ipaf.accounting-status.update'), [
        'protected_area_id' => $area->id, 'reporting_year' => 2026,
        'total_ipaf_collection' => '1000.00', 'bank_balance' => '750.00', 'status_note' => 'Verified',
    ])->assertSessionHasNoErrors();
    expect(IpafAccountingStatus::query()->where('protected_area_id', $area->id)->value('bank_balance'))->toBe('750.00')
        ->and(IpafRevenueTarget::query()->where('protected_area_id', $area->id)->where('quarter', 2)->value('target_amount'))->toBe('200.00');
});
