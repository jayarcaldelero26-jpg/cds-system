<?php

use App\Models\IpafAccountingStatus;
use App\Models\IpafRevenueTarget;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    foreach (['technical-reports.view', 'technical-reports.update'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

test('IPAF index supplies the restored analytics, annual target, and accounting data sections', function () {
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
