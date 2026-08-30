<?php

use App\Domain\Modules\ProgramArea;
use App\Models\ModuleDefinition;
use App\Models\NonWorkingDay;
use App\Models\ConservationReportSubmission;
use App\Models\User;
use App\Services\CalendarMovEventService;
use App\Services\BusinessCalendarService;
use App\Services\Modules\ModuleDeadlineService;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function moduleManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        Permission::findOrCreate('module-definitions.view', 'web'),
        Permission::findOrCreate('module-definitions.create', 'web'),
        Permission::findOrCreate('module-definitions.update', 'web'),
        Permission::findOrCreate('module-definitions.activate', 'web'),
    ]);
    return $user;
}

function modulePayload(array $overrides = []): array
{
    return [...[
        'name' => 'Regular PAMB Meeting', 'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT->value,
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET, 'reporting_frequency' => 'quarterly',
        'deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'default_deadline_days' => 15,
        'allow_deadline_override' => false, 'description' => 'Registry test', 'is_active' => true,
    ], ...$overrides];
}

test('module definition management requires its explicit permissions', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('module-definitions.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('module-definitions.store'), modulePayload())->assertForbidden();
});

test('a manager can create regular target plan custom and no deadline module definitions with stable codes', function () {
    $manager = moduleManager();
    $this->actingAs($manager)->get(route('module-definitions.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Settings/ModuleManagement'));

    $this->actingAs($manager)->post(route('module-definitions.store'), modulePayload())->assertSessionHas('success');
    $regular = ModuleDefinition::query()->firstOrFail();
    expect($regular->code)->toBe('regular_pamb_meeting')->and($regular->implementation_type)->toBe(ModuleDefinition::IMPLEMENTATION_GENERIC);

    $this->actingAs($manager)->put(route('module-definitions.update', $regular), modulePayload(['name' => 'Regular PAMB Meeting Updated']))->assertSessionHas('success');
    expect($regular->fresh()->code)->toBe('regular_pamb_meeting')->and($regular->fresh()->name)->toBe('Regular PAMB Meeting Updated');

    $this->actingAs($manager)->post(route('module-definitions.store'), modulePayload([
        'name' => 'Protected Area Management Plan', 'module_type' => ModuleDefinition::TYPE_PLAN,
        'reporting_frequency' => null, 'plan_duration_years' => 10,
        'deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM, 'default_deadline_days' => null, 'allow_deadline_override' => true,
    ]))->assertSessionHas('success');
    $this->actingAs($manager)->post(route('module-definitions.store'), modulePayload([
        'name' => 'Reference Plan', 'deadline_mode' => ModuleDefinition::DEADLINE_NONE, 'default_deadline_days' => null,
    ]))->assertSessionHas('success');
    expect(ModuleDefinition::query()->count())->toBe(3);
});

test('module definition conditional validation protects registry configuration', function () {
    $manager = moduleManager();
    $this->actingAs($manager)->post(route('module-definitions.store'), modulePayload(['reporting_frequency' => null]))->assertSessionHasErrors('reporting_frequency');
    $this->actingAs($manager)->post(route('module-definitions.store'), modulePayload(['module_type' => ModuleDefinition::TYPE_PLAN, 'reporting_frequency' => null, 'plan_duration_years' => null]))->assertSessionHasErrors('plan_duration_years');
    $this->actingAs($manager)->post(route('module-definitions.store'), modulePayload(['default_deadline_days' => 0]))->assertSessionHasErrors('default_deadline_days');
});

test('module definitions deactivate rather than delete and existing registration seeding is idempotent', function () {
    $manager = moduleManager();
    $definition = ModuleDefinition::query()->create([...modulePayload(), 'code' => 'regular_pamb_meeting']);
    $this->actingAs($manager)->patch(route('module-definitions.status', $definition))->assertSessionHas('success');
    expect($definition->fresh()->is_active)->toBeFalse()->and(ModuleDefinition::query()->find($definition->id))->not->toBeNull();

    $this->seed(ModuleDefinitionSeeder::class);
    $count = ModuleDefinition::query()->count();
    $this->seed(ModuleDefinitionSeeder::class);
    expect(ModuleDefinition::query()->count())->toBe($count)->and(ModuleDefinition::query()->where('code', 'bms')->count())->toBe(1);
});

test('module deadline policy uses the existing business calendar service for standard and handles custom and none', function () {
    BusinessCalendarService::forgetCache();
    NonWorkingDay::query()->create(['date' => '2026-08-21', 'name' => 'Holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    $standard = ModuleDefinition::query()->create([...modulePayload(), 'code' => 'standard_module', 'default_deadline_days' => 2]);
    $policy = app(ModuleDeadlineService::class);
    expect($policy->resolve($standard, '2026-08-20', null, '2026-08-25')['deadline_date'])->toBe('2026-08-25')->and($policy->resolve($standard, '2026-08-20', null, '2026-08-25')['processing_days'])->toBe(2);

    $custom = ModuleDefinition::query()->create([...modulePayload(['deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM, 'default_deadline_days' => null, 'allow_deadline_override' => true]), 'code' => 'custom_module']);
    $none = ModuleDefinition::query()->create([...modulePayload(['deadline_mode' => ModuleDefinition::DEADLINE_NONE, 'default_deadline_days' => null]), 'code' => 'none_module']);
    expect($policy->resolve($custom, '2026-08-20', '2026-09-01')['deadline_date'])->toBe('2026-09-01')->and($policy->resolve($none, '2026-08-20')['deadline_date'])->toBeNull();
});

test('active generic definitions use the existing conservation report route while inactive definitions remain historically readable', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    $definition = ModuleDefinition::query()->create([...modulePayload(), 'code' => 'registry_backed_module']);

    $this->actingAs($viewer)->get(route('conservation-reports.index', $definition->code))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ConservationReports/Index')->where('workflow.key', $definition->code)->where('workflow.label', $definition->name));

    $definition->update(['is_active' => false]);
    $this->actingAs($viewer)->get(route('conservation-reports.index', $definition->code))->assertOk();
});

test('a dynamic generic report keeps one module policy and presentation contract', function () {
    $viewer = moduleManager();
    $viewer->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    $definition = ModuleDefinition::query()->create([...modulePayload([
        'name' => 'Sample Quarterly Report',
        'deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS,
        'default_deadline_days' => 15,
    ]), 'code' => 'sample_quarterly_report']);
    $area = \App\Models\ProtectedArea::create(['name' => 'Dynamic Module PA', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $viewer->id, 'updated_by' => $viewer->id]);
    $record = ConservationReportSubmission::query()->create([
        'workflow_key' => $definition->code, 'protected_area_id' => $area->id, 'target_office' => 'PENRO Davao Oriental',
        'activity_name' => 'General Report', 'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-01', 'date_accomplished' => '2026-08-03', 'date_received_penro' => '2026-08-24',
        'created_by' => $viewer->id, 'updated_by' => $viewer->id,
    ]);

    $this->actingAs($viewer)->get(route('conservation-reports.index', $definition->code))
        ->assertInertia(fn ($page) => $page
            ->where('workflow.key', $definition->code)
            ->where('workflow.label', $definition->name)
            ->where('workflow.deadline_mode', ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS)
            ->where('workflow.default_deadline_days', 15)
            ->where('submissions.data.0.id', $record->id)
            ->where('submissions.data.0.target_office', $record->target_office)
            ->where('submissions.data.0.date_accomplished', '2026-08-03')
            ->where('submissions.data.0.deadline_submission', $record->deadline_submission)
            ->where('submissions.data.0.submission_status', 'Report Submitted')
            ->where('submissions.data.0.timeliness', $record->timeliness));

    $event = app(CalendarMovEventService::class)->events($viewer, \Carbon\CarbonImmutable::parse('2026-08-01'), 'conservation-reports')->firstWhere('id', $record->id);
    expect($event)->not->toBeNull()->and($event['program_area'])->toBe($definition->program_area->value);
});

test('dynamic custom and no-deadline reports do not fabricate deadline or timeliness', function () {
    $custom = ModuleDefinition::query()->create([...modulePayload(['name' => 'Custom Dynamic Report', 'deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM, 'default_deadline_days' => null, 'allow_deadline_override' => true]), 'code' => 'custom_dynamic_report']);
    $none = ModuleDefinition::query()->create([...modulePayload(['name' => 'No Deadline Dynamic Report', 'deadline_mode' => ModuleDefinition::DEADLINE_NONE, 'default_deadline_days' => null]), 'code' => 'no_deadline_dynamic_report']);
    foreach ([$custom, $none] as $definition) {
        $record = ConservationReportSubmission::query()->create(['workflow_key' => $definition->code, 'activity_name' => 'General Report', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-08-03', 'date_received_penro' => '2026-08-24']);
        expect($record->deadline_submission)->toBeNull()->and($record->timeliness)->toBe('No Data');
    }
});
