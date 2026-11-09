<?php

use App\Models\BmsRecord;
use App\Models\BmsReportSubmission;
use App\Models\BmsThreat;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->withoutVite();
    config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
    app()->forgetInstance('inertia.view-finder');
});

function bmsViewCostFixture(): array
{
    $actor = User::factory()->create(['section' => 'CDS', 'unit_assignment' => null]);
    $actor->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $area = ProtectedArea::create([
        'name' => 'BMS view fixture', 'category' => 'National Park',
        'municipality' => 'Fixture', 'province' => 'Fixture', 'region' => 'XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $record = BmsRecord::create([
        'protected_area_id' => $area->id, 'monitoring_date' => '2026-01-10',
        'station' => '1', 'category' => 'Flora', 'taxonomic_group' => 'Tree',
        'species_common_name' => 'Fixture tree', 'species_scientific_name' => 'Fixture species',
        'count' => 1, 'observer_name' => 'Fixture observer',
    ]);
    BmsThreat::create(['protected_area_id' => $area->id, 'date' => '2026-01-10', 'threat_type' => 'Fixture threat']);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Fixture monitoring', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-01-10',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    return [$actor, $area, $record, $report];
}

test('BMS tracker retains reports and source options without reading hidden observation datasets', function (): void {
    [$actor, $area, $record, $report] = bmsViewCostFixture();
    $reads = [];
    DB::listen(function ($query) use (&$reads): void {
        if (preg_match('/\b(?:from|join)\s+["\x60]?([a-z_]+)["\x60]?/i', $query->sql, $match)) {
            $reads[] = strtolower($match[1]);
        }
    });

    $this->actingAs($actor)->get(route('bms.index', ['tracker' => 1]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bms/Index')
            ->has('bmsRecords', 0)->has('bmsThreats', 0)->has('spatialLayers', 0)
            ->where('annexHeaderMetadata', null)
            ->has('protectedAreas', 1)
            ->has('reportSubmissions.data', 1)
            ->where('reportSubmissions.data.0.id', $report->id));

    expect(array_intersect($reads, ['bms_records', 'bms_threats', 'spatial_layers', 'bms_annex_headers']))->toBe([]);
    expect(BmsRecord::find($record->id))->not->toBeNull();
});

test('BMS data navigation still returns the complete authorized observation and threat datasets', function (): void {
    [$actor, $area, $record, $report] = bmsViewCostFixture();
    $this->actingAs($actor)->get(route('bms.index', ['tracker' => 1]))->assertOk();
    $this->get(route('bms.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Bms/Index')
        ->has('bmsRecords', 1)->where('bmsRecords.0.id', $record->id)
        ->has('bmsThreats', 1)->has('reportSubmissions.data', 1));
});

test('BMS tracker flag does not bypass an explicit protected-area scope check', function (): void {
    [$actor, $area] = bmsViewCostFixture();
    $outside = ProtectedArea::create([
        'name' => 'Outside BMS fixture', 'category' => 'National Park',
        'municipality' => 'Fixture', 'province' => 'Fixture', 'region' => 'XI',
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $pamo = User::factory()->create([
        'section' => 'PAMO', 'unit_assignment' => 'conservation',
        'protected_area_id' => $area->id,
    ]);
    $pamo->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $this->actingAs($pamo)->get(route('bms.index', ['tracker' => 1, 'protected_area_id' => $outside->id]))
        ->assertForbidden();
});
