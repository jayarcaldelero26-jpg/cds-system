<?php

use App\Models\ModuleDefinition;
use App\Services\BusinessCalendarService;
use App\Services\Reports\ReportRequirementRegistry;
use Database\Seeders\ModuleDefinitionSeeder;

test('requirement deadlines are calculated once per period instead of once per protected area', function (): void {
    $definition = ModuleDefinition::create([
        'name' => 'Generation cost fixture', 'code' => 'generation_cost_fixture',
        'program_area' => 'protected_area_management_and_development',
        'requirement_domain' => 'pa', 'requirement_key' => 'generation_cost_fixture',
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
        'reporting_frequency' => 'monthly',
        'deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS,
        'default_deadline_days' => 2, 'is_active' => true,
    ]);
    $calendar = Mockery::mock(BusinessCalendarService::class)->makePartial();
    $calendar->shouldReceive('addConservationWorkingDays')->times(12)->passthru();
    app()->instance(BusinessCalendarService::class, $calendar);
    $targets = array_map(fn (int $id): array => ['id' => $id, 'name' => 'Fixture PA '.$id], range(1, 6));
    $rows = app(ReportRequirementRegistry::class)->generate('pa', 2026, $targets)
        ->where('definition_id', $definition->id);

    expect($rows)->toHaveCount(72)
        ->and($rows->pluck('identity')->unique())->toHaveCount(72)
        ->and($rows->first()['deadline'])->toBe('2026-02-03');

    foreach ($rows->groupBy('period_key') as $periodRows) {
        expect($periodRows->pluck('protected_area_id')->unique())->toHaveCount(6)
            ->and($periodRows->pluck('deadline')->unique())->toHaveCount(1);
    }
});

test('generated schedules preserve public deadline semantics across domains years and module edits', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $registry = app(ReportRequirementRegistry::class);
    foreach ([2026, 2027] as $year) {
        foreach (['pa', 'engp'] as $domain) {
            $targets = $domain === 'pa'
                ? [['id' => 21, 'name' => 'Fixture A'], ['id' => 22, 'name' => 'Fixture B']]
                : ['CENRO Mati', 'CENRO Baganga'];
            $rows = $registry->generate($domain, $year, $targets);
            expect($rows)->not->toBeEmpty();
            foreach ($rows as $row) {
                expect($row['deadline'])->toBe($registry->deadline($domain, $row['workflow_key'], $year, $row['period_key']));
            }
        }
    }

    $definition = ModuleDefinition::where('code', 'engp_cbep')->firstOrFail();
    $definition->update(['deadline_mode' => ModuleDefinition::DEADLINE_NONE]);
    expect($registry->generate('engp', 2026, ['CENRO Mati'])->where('workflow_key', 'cbep')->pluck('deadline')->unique()->all())
        ->toBe([null]);

    $definition->update(['is_active' => false]);
    expect($registry->generate('engp', 2026, ['CENRO Mati'])->where('workflow_key', 'cbep'))->toBeEmpty();
});
