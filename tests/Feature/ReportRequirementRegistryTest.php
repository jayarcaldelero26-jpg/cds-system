<?php

use App\Models\ModuleDefinition;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Reports\ReportRequirementRegistry;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
});

test('module definitions are the canonical runtime source for conservation and ENGP requirements', function () {
    $registry = app(ReportRequirementRegistry::class);
    $engp = ModuleDefinition::query()->where('code', 'engp_cbep')->firstOrFail();
    $pa = ModuleDefinition::query()->where('code', 'regular_pamb')->firstOrFail();

    expect($registry->find(ReportRequirementRegistry::ENGP, 'cbep')['id'])->toBe($engp->id)
        ->and($registry->find(ReportRequirementRegistry::PA, 'regular_pamb')['id'])->toBe($pa->id)
        ->and($registry->find(ReportRequirementRegistry::PA, 'regular_pamb')['requirement_metadata'])->toBeArray();
});

test('inactive definitions stop new expected requirement generation without deleting history', function () {
    $definition = ModuleDefinition::query()->where('code', 'engp_cbep')->firstOrFail();
    $definition->update(['is_active' => false]);

    expect(app(ReportRequirementRegistry::class)->generate(ReportRequirementRegistry::ENGP, 2027)->where('workflow_key', 'cbep'))->toBeEmpty()
        ->and(ModuleDefinition::query()->whereKey($definition->id)->exists())->toBeTrue()
        ->and(app(EngpReportWorkflowRegistry::class)->periods('cbep', 2027))->toBeEmpty();
});

test('effective dates select the definition for the requested reporting year', function () {
    ModuleDefinition::query()->where('code', 'engp_cbep')->update([
        'effective_from' => '2027-01-01',
        'effective_to' => '2027-12-31',
    ]);

    $registry = app(ReportRequirementRegistry::class);
    expect($registry->periods(ReportRequirementRegistry::ENGP, 'cbep', 2026))->toBeEmpty()
        ->and($registry->periods(ReportRequirementRegistry::ENGP, 'cbep', 2027))->toHaveCount(12)
        ->and($registry->periods(ReportRequirementRegistry::ENGP, 'cbep', 2028))->toBeEmpty()
        ->and($registry->period(ReportRequirementRegistry::ENGP, 'cbep', 2027, '2027-01')['label'])->toBe('January 2027');
});

test('ENGP generation supports arbitrary years and stable office-period identities', function () {
    $registry = app(ReportRequirementRegistry::class);
    $requirements = $registry->generate(ReportRequirementRegistry::ENGP, 2027, ['CENRO Baganga']);
    $january = $requirements->first(fn (array $row): bool => $row['workflow_key'] === 'cbep' && $row['period_key'] === '2027-01');
    $identity2026 = $registry->identity(ReportRequirementRegistry::ENGP, 'cbep', 'development_office', 'cenro_baganga', 2026, '2026-01');
    $identity2027 = $registry->identity(ReportRequirementRegistry::ENGP, 'cbep', 'development_office', 'cenro_baganga', 2027, '2027-01');

    expect($requirements->where('workflow_key', 'cbep'))->toHaveCount(12)
        ->and($january['office'])->toBe('CENRO Baganga')
        ->and($january['identity'])->toBe('engp|cbep|development_office|cenro_baganga|2027|2027-01')
        ->and($january['deadline'])->toBe('2027-01-20')
        ->and($identity2026)->not->toBe($identity2027);
});

test('ENGP monthly quarterly and weekly schedules roll over through 2028', function () {
    $registry = app(EngpReportWorkflowRegistry::class);

    expect($registry->periods('cbep', 2027))->toHaveCount(12)
        ->and($registry->periods('ngp_produce', 2027))->toHaveCount(4)
        ->and($registry->deadline('ngp_produce', 2027, 'Q4'))->toBe('2027-12-10')
        ->and($registry->periods('weekly_accomplishment', 2027))->not->toBeEmpty()
        ->and($registry->periods('weekly_accomplishment', 2027)[0]['label'])->toContain('Jan 4')
        ->and($registry->periods('weekly_accomplishment', 2028))->not->toBeEmpty()
        ->and($registry->periods('weekly_accomplishment', 2028)[0]['label'])->toContain('Jan 3')
        ->and($registry->periods('cbep', 2028)[11]['key'])->toBe('2028-12')
        ->and($registry->deadline('cbep', 2028, '2028-02'))->toBe('2028-02-20');
});

test('ENGP year choices come from the canonical registry and existing records', function () {
    $years = app(EngpReportWorkflowRegistry::class)->availableYears([2024, 2026], 2026);

    expect($years)->toContain(2024)
        ->and($years)->toContain(2026)
        ->and($years)->toContain(2027)
        ->and($years)->toContain(2028)
        ->and($years)->toEqual(collect($years)->sortDesc()->values()->all());
});

test('editable canonical ENGP identity, frequency, and deadline fields affect runtime resolution', function () {
    $definition = ModuleDefinition::query()->where('code', 'engp_cbep')->firstOrFail();
    $definition->update(['name' => 'CBEP Updated', 'reporting_frequency' => 'quarterly', 'deadline_mode' => ModuleDefinition::DEADLINE_NONE]);

    $registry = app(ReportRequirementRegistry::class);
    expect($registry->find(ReportRequirementRegistry::ENGP, 'cbep')['label'])->toBe('CBEP Updated')
        ->and($registry->periods(ReportRequirementRegistry::ENGP, 'cbep', 2027))->toHaveCount(4)
        ->and($registry->deadline(ReportRequirementRegistry::ENGP, 'cbep', 2027, 'Q1'))->toBeNull();

    $definition->update(['deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'default_deadline_days' => 2]);
    expect($registry->deadline(ReportRequirementRegistry::ENGP, 'cbep', 2027, 'Q1'))->toBe('2027-04-02');
});

test('PA generation uses protected_area_id identity and preserves explicit legacy periods', function () {
    $registry = app(ReportRequirementRegistry::class);
    $requirements = $registry->generate(ReportRequirementRegistry::PA, 2026, [['id' => 42, 'name' => 'Test PA']]);
    $pamb = $requirements->filter(fn (array $row): bool => $row['workflow_key'] === 'regular_pamb');
    $homestay = $requirements->filter(fn (array $row): bool => $row['workflow_key'] === 'homestay');

    expect($pamb)->toHaveCount(4)
        ->and($pamb->first()['identity'])->toBe('pa|regular_pamb|protected_area|42|2026|quarter_1')
        ->and($homestay)->toHaveCount(3);
});

test('retired definitions are never generated', function () {
    $definition = ModuleDefinition::query()->create([
        'name' => 'Legacy LAWIN', 'code' => 'cds_lawin', 'program_area' => 'development',
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET, 'reporting_frequency' => 'monthly',
        'deadline_mode' => ModuleDefinition::DEADLINE_NONE, 'is_active' => true,
        'requirement_domain' => ReportRequirementRegistry::PA, 'requirement_key' => 'cds_lawin',
    ]);

    expect(app(ReportRequirementRegistry::class)->find(ReportRequirementRegistry::PA, 'cds_lawin'))->toBeNull()
        ->and(ModuleDefinition::query()->whereKey($definition->id)->exists())->toBeTrue();
});
