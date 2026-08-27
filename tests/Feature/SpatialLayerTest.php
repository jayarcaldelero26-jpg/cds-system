<?php

use App\Models\ProtectedArea;
use App\Models\SpatialLayer;
use App\Models\User;
use App\Services\SpatialLayerService;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function spatialFeatureCollection(float $longitude = 126.2): array
{
    return ['type' => 'FeatureCollection', 'features' => [[
        'type' => 'Feature', 'properties' => [],
        'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, 7.0]],
    ]]];
}

it('adds spatial uploads as separate layers without overwriting earlier layers', function () {
    $user = User::factory()->create(['is_active' => true]);
    $role = Role::create(['name' => 'Spatial Test Manager', 'guard_name' => 'web']);
    $role->syncPermissions([
        Permission::create(['name' => 'bms.view', 'guard_name' => 'web']),
        Permission::create(['name' => 'gis.manage', 'guard_name' => 'web']),
    ]);
    $user->assignRole($role);

    $area = ProtectedArea::create([
        'name' => 'Test Protected Area', 'category' => 'National Park', 'municipality' => 'Test',
        'province' => 'Test', 'region' => 'XI', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    $first = app(SpatialLayerService::class)->create([
        'protected_area_id' => $area->id, 'name' => 'Boundary', 'geojson' => spatialFeatureCollection(),
    ], $user->id);

    $response = $this->actingAs($user)->post(route('bms.import-geojson'), [
        'protected_area_id' => $area->id,
        'layer_name' => 'Transect',
        'spatial_geojson' => json_encode(spatialFeatureCollection(126.3)),
        'tab' => 'semestral',
        'category' => 'Flora',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'year' => '2026',
    ]);
    $response->assertRedirect(route('bms.index', [
        'protected_area_id' => $area->id,
        'tab' => 'semestral',
        'category' => 'Flora',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'year' => '2026',
    ]));

    $indexResponse = $this->actingAs($user)->get(route('bms.index', [
        'protected_area_id' => $area->id,
    ]));

    $indexResponse->assertInertia(fn ($page) => $page
        ->component('Bms/Index')
        ->where('filters.protected_area_id', (string) $area->id)
        ->has('spatialLayers', 2)
        ->where('spatialLayers.0.name', 'Transect')
        ->where('spatialLayers.1.name', 'Boundary'));

    $second = SpatialLayer::where('name', 'Transect')->firstOrFail();

    expect(SpatialLayer::where('protected_area_id', $area->id)->count())->toBe(2)
        ->and($first->id)->not->toBe($second->id)
        ->and($first->fresh()->name)->toBe('Boundary');
});

it('rejects a shapefile zip without required components', function () {
    $zip = UploadedFile::fake()->createWithContent('bad.zip', 'not a zip');

    expect(fn () => app(SpatialLayerService::class)->validateShapefileZip($zip))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
