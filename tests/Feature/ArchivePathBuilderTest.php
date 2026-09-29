<?php

use App\Models\ModuleDefinition;
use App\Services\Archive\ArchivePathBuilder;
use Database\Seeders\ModuleDefinitionSeeder;

test('archive path uses the canonical unit, actual module name, and tracking filename', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $builder = app(ArchivePathBuilder::class);

    $conservation = ModuleDefinition::query()->where('code', 'maintenance_pa_information_system')->firstOrFail();
    expect($builder->build($conservation, 'Conservation Unit', 'CENRO Mati', '2026-CDS-000001'))->toMatchArray([
        'unit' => 'Conservation Unit',
        'office' => 'CENRO Mati',
        'module' => 'Maintenance of PA Information System',
        'segments' => ['Conservation Unit', 'CENRO Mati', 'Maintenance of PA Information System'],
        'filename' => '2026-CDS-000001.pdf',
    ]);

    $development = ModuleDefinition::query()->where('code', 'management_plans')->firstOrFail();
    expect($builder->build($development, 'Development Unit', 'CENRO Baganga', '2026-CDS-000002')['segments'])
        ->toBe(['Development Unit', 'CENRO Baganga', 'Management Plans']);
});

test('archive filenames preserve readable activity names, sanitize path characters, and add one PDF extension', function (): void {
    $module = new ModuleDefinition(['name' => 'Activity Reports', 'is_active' => true]);
    $builder = app(ArchivePathBuilder::class);

    expect($builder->build($module, 'Conservation Unit', 'CENRO Mati', 'International Coastal Cleanup 2026')['filename'])
        ->toBe('International Coastal Cleanup 2026.pdf')
        ->and($builder->build($module, 'Conservation Unit', 'CENRO Mati', 'International Coastal Cleanup/2026.PDF.pdf')['filename'])
        ->toBe('International Coastal Cleanup-2026.pdf')
        ->and($builder->build($module, 'Conservation Unit', 'CENRO Mati', 'International Coastal Cleanup 2026.PDF. ')['filename'])
        ->toBe('International Coastal Cleanup 2026.pdf');
});

test('activity archive filenames fit the 255-character metadata column without splitting Unicode', function (): void {
    $module = new ModuleDefinition(['name' => 'Activity Reports', 'is_active' => true]);
    $builder = app(ArchivePathBuilder::class);

    foreach (['A', 'ñ'] as $character) {
        $filename = $builder->build($module, 'Conservation Unit', 'CENRO Mati', str_repeat($character, 255))['filename'];

        expect(mb_strlen($filename))->toBe(255)
            ->and($filename)->toBe(str_repeat($character, 251).'.pdf');
    }
});

test('all seeded active non-retired modules have a canonical archive unit classification', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $builder = app(ArchivePathBuilder::class);
    $tracking = app(\App\Services\SubmissionTracking\SubmissionTrackingService::class);
    $modules = ModuleDefinition::query()->active()->notRetired()->get();
    $specializedSources = [
        'bms' => 'bms', 'bams' => 'bams', 'imea' => 'imea',
        'imea_facility_maintenance' => 'imea-maintenance',
        'automated_weather_station' => 'aws', 'ipaf_management' => 'ipaf-management',
        'revenue_collection' => 'revenue', 'management_plans' => 'management-plans',
    ];

    expect($modules)->not->toBeEmpty();
    foreach ($modules as $module) {
        $sourceKey = str_starts_with($module->code, 'engp_')
            ? 'engp'
            : ($module->implementation_type === ModuleDefinition::IMPLEMENTATION_GENERIC ? 'conservation' : ($specializedSources[$module->code] ?? null));
        expect($sourceKey)->toBeString();
        $source = $tracking->source($sourceKey);
        expect($source)->toBeArray()->and($source['archive_module_code'] ?? null)->toBeCallable();
        expect($source['archive_office_attribute'] ?? null)->toBeString();
        $modelClass = $source['model'];
        $record = new $modelClass();
        if ($sourceKey === 'conservation') $record->setAttribute('workflow_key', $module->code);
        if ($sourceKey === 'engp') $record->setAttribute('workflow_key', substr($module->code, 5));
        expect(($source['archive_module_code'])($record))->toBe($module->code);

        $archiveUnit = $source['archive_unit'] ?? null;
        $path = $builder->build($module, (string) $archiveUnit, 'CENRO Mati', '2026-CDS-000001');
        expect($path['segments'])->toHaveCount(3)
            ->and($path['segments'][0])->toBeIn(['Conservation Unit', 'Development Unit'])
            ->and($path['segments'][1])->toBe('CENRO Mati')
            ->and($path['segments'][2])->toBe(str_replace('/', '-', $module->name))
            ->and($path['filename'])->toBe('2026-CDS-000001.pdf');
    }
});

test('all thirteen active Development modules use the shared extended route and archive checkpoint', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $developmentCodes = [
        'management_plans', 'engp_cenro_nursery_seedling', 'engp_cbep', 'engp_elcac',
        'engp_weekly_accomplishment', 'engp_forest_disturbance', 'engp_monthly_accomplishment_pmd_fmb',
        'engp_ngp_produce', 'engp_ngp_staff_accomplishment', 'engp_nursery_maintenance',
        'engp_site_visit', 'engp_tree_replacement', 'engp_rims',
    ];
    $modules = ModuleDefinition::query()->active()->notRetired()->whereIn('code', $developmentCodes)->get()->keyBy('code');
    $tracking = app(\App\Services\SubmissionTracking\SubmissionTrackingService::class);
    $profiles = app(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::class);
    $builder = app(ArchivePathBuilder::class);

    expect($modules->keys()->sort()->values()->all())->toBe(collect($developmentCodes)->sort()->values()->all());

    foreach ($developmentCodes as $code) {
        $module = $modules->get($code);
        $sourceKey = $code === 'management_plans' ? 'management-plans' : 'engp';
        $source = $tracking->source($sourceKey);
        $record = new ($source['model'])();
        if ($sourceKey === 'engp') $record->setAttribute('workflow_key', substr($code, 5));
        $action = collect($profiles->actionProfile($sourceKey)['actions'])->firstWhere('key', 'forward_to_office_penro');
        $path = $builder->build($module, 'Development Unit', 'CENRO Baganga', '2026-CDS-000003');

        expect($action)->not->toBeNull()
            ->and($action['from'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PENRO_RECORDS)
            ->and($action['to'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
            ->and($action)->not->toHaveKey('internal_only')
            ->and($source['archive_unit'])->toBe('Development Unit')
            ->and(($source['archive_module_code'])($record))->toBe($code)
            ->and($path['segments'])->toBe(['Development Unit', 'CENRO Baganga', str_replace('/', '-', $module->name)])
            ->and($path['filename'])->toBe('2026-CDS-000003.pdf');
    }
});

test('unknown source unit cannot be silently classified for archive storage', function (): void {
    expect(fn () => app(ArchivePathBuilder::class)->build(new ModuleDefinition([
        'code' => 'unmapped_test_module', 'name' => 'Unmapped Test Module',
        'program_area' => 'conservation', 'is_active' => true,
        ]), 'Unmapped Unit', 'CENRO Mati', '2026-CDS-000001'))
        ->toThrow(RuntimeException::class);
});

test('archive office resolver uses exact canonical source office and rejects non-CENRO or conflicting ownership', function (): void {
    $resolver = app(\App\Services\Archive\ArchiveOfficeResolver::class);
    $model = new \App\Models\EngpReportSubmission(['office' => 'CENRO Baganga']);
    expect($resolver->resolve(['archive_office_attribute' => 'office'], $model))->toBe('CENRO Baganga');

    $unmapped = new \App\Models\EngpReportSubmission(['office' => 'PENRO Davao Oriental']);
    expect(fn () => $resolver->resolve(['archive_office_attribute' => 'office'], $unmapped))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'ARCHIVE OFFICE UNMAPPED');
});

test('ENGP source office stays independent for Baganga and Manay regardless of routing holder', function (): void {
    $resolver = app(\App\Services\Archive\ArchiveOfficeResolver::class);
    foreach (['CENRO Baganga', 'CENRO Manay'] as $office) {
        $sourceRecord = new \App\Models\EngpReportSubmission(['office' => $office]);
        expect($resolver->resolve(['archive_office_attribute' => 'office'], $sourceRecord))->toBe($office);
    }
});

test('protected area supervising office is canonical and conflicting source ownership fails closed', function (): void {
    $admin = \App\Models\User::factory()->create();
    $area = \App\Models\ProtectedArea::create([
        'name' => 'FINAL-UAT Office Resolution PA', 'short_name' => 'FINAL-UAT-OR',
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'XI', 'status' => 'Active', 'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    $office = \App\Models\OrganizationalOffice::query()->where('code', 'cenro_mati')->firstOrFail();
    \App\Models\ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id, 'organizational_office_id' => $office->id,
        'assignment_type' => 'supervising', 'assigned_by' => $admin->id,
    ]);
    $resolver = app(\App\Services\Archive\ArchiveOfficeResolver::class);
    $record = new \App\Models\ConservationReportSubmission(['protected_area_id' => $area->id, 'target_office' => 'CENRO Mati']);
    expect($resolver->resolve(['archive_office_attribute' => 'target_office'], $record))->toBe('CENRO Mati');

    $conflict = new \App\Models\ConservationReportSubmission(['protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga']);
    expect(fn () => $resolver->resolve(['archive_office_attribute' => 'target_office'], $conflict))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'ARCHIVE OFFICE UNMAPPED');

    $penroOffice = \App\Models\OrganizationalOffice::query()->where('code', 'penro_davao_oriental')->firstOrFail();
    $directPenroArea = \App\Models\ProtectedArea::create([
        'name' => 'FINAL-UAT Direct PENRO PA', 'short_name' => 'FINAL-UAT-DP',
        'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental',
        'region' => 'XI', 'status' => 'Active', 'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    \App\Models\ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $directPenroArea->id, 'organizational_office_id' => $penroOffice->id,
        'assignment_type' => 'supervising', 'assigned_by' => $admin->id,
    ]);
    $falseCenroOwner = new \App\Models\ConservationReportSubmission(['protected_area_id' => $directPenroArea->id, 'target_office' => 'CENRO Mati']);
    expect(fn () => $resolver->resolve(['archive_office_attribute' => 'target_office'], $falseCenroOwner))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'ARCHIVE OFFICE UNMAPPED');

    $legacyDirectPenroArea = \App\Models\ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental',
        'region' => 'XI', 'status' => 'Active', 'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    $legacyWrongOwner = new \App\Models\ConservationReportSubmission(['protected_area_id' => $legacyDirectPenroArea->id, 'target_office' => 'CENRO Mati']);
    expect(fn () => $resolver->resolve(['archive_office_attribute' => 'target_office'], $legacyWrongOwner))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'ARCHIVE OFFICE UNMAPPED');
});
