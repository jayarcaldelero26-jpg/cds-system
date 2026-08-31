<?php

use App\Models\AuditLog;
use App\Models\ModuleDefinition;
use App\Models\ProtectedArea;
use App\Models\TechnicalReport;
use App\Models\User;
use App\Services\CalendarMovEventService;
use App\Services\Compliance\OverdueReportService;
use App\Services\GlobalSearchService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach ([
        'technical-reports.view',
        'reports.view',
        'audit-logs.view',
        'module-definitions.view',
        'module-definitions.update',
        'module-definitions.activate',
    ] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    $this->area = ProtectedArea::create([
        'name' => 'Retirement Compatibility PA',
        'short_name' => 'RCPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
});

function historicalTechnicalReport(User $user, ProtectedArea $area, array $overrides = []): TechnicalReport
{
    return TechnicalReport::create(array_merge([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Baganga',
        'report_type' => 'Historical Technical Report',
        'semester' => '1st Semester',
        'activity_name' => 'Historical technical record',
        'date_accomplished' => '2026-08-01',
        'submission_date' => '2026-08-05',
        'status' => 'Submitted',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], $overrides));
}

test('Technical Reports user-facing routes are retired without a redirect', function () {
    foreach ([
        'technical-reports.index',
        'technical-reports.create',
        'technical-reports.store',
        'technical-reports.edit',
        'technical-reports.attachment.show',
        'technical-reports.update',
        'technical-reports.destroy',
    ] as $name) {
        expect(Route::getRoutes()->getByName($name))->toBeNull();
    }

    $this->actingAs($this->user)->get('/technical-reports')->assertNotFound();
    $this->actingAs($this->user)->post('/technical-reports')->assertNotFound();
});

test('historical Technical Report records remain intact and historical audit references remain readable', function () {
    $report = historicalTechnicalReport($this->user, $this->area, ['attachment' => 'technical-reports/historical.pdf']);
    $log = AuditLog::query()->create([
        'event_type' => 'historical',
        'action' => 'Historical Technical Report Reviewed',
        'entity_type' => TechnicalReport::class,
        'entity_id' => (string) $report->id,
        'module' => 'Technical Reports',
        'summary' => 'Historical Technical Report reference.',
        'metadata' => ['source_id' => $report->id],
        'user_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('technical_reports', [
        'id' => $report->id,
        'report_type' => 'Historical Technical Report',
        'attachment' => 'technical-reports/historical.pdf',
    ]);

    $this->actingAs($this->user)->getJson(route('audit-logs.show', $log))
        ->assertOk()
        ->assertJsonPath('entity_type', TechnicalReport::class)
        ->assertJsonPath('entity_id', (string) $report->id)
        ->assertJsonPath('module', 'Technical Reports');
});

test('retired Technical Reports are excluded from tracking, compliance, calendar, and global search', function () {
    $report = historicalTechnicalReport($this->user, $this->area, [
        'activity_name' => 'Retired Technical Report Search Marker',
        'date_accomplished' => '2026-08-01',
        'submission_date' => null,
    ]);

    $tracking = app(SubmissionTrackingService::class);
    $alerts = app(OverdueReportService::class);
    $calendar = app(CalendarMovEventService::class);

    expect($tracking->records()->pluck('source_id')->all())->not->toContain($report->id)
        ->and($alerts->sourceDefinitions())->not->toHaveKey(TechnicalReport::class)
        ->and($alerts->overdueReports()->pluck('sourceType')->all())->not->toContain(TechnicalReport::class)
        ->and($alerts->destinationReferences()->pluck('source_id')->all())->not->toContain($report->id)
        ->and($calendar->modules($this->user))->not->toContain(['key' => 'technical-reports', 'label' => 'Technical Reports'])
        ->and($calendar->events($this->user, CarbonImmutable::parse('2026-08-01', 'Asia/Manila'))->pluck('source_type')->all())->not->toContain('technical-reports')
        ->and(app(GlobalSearchService::class)->search($this->user, 'Retired Technical Report Search Marker')['total'])->toBe(0);
});

test('retired ModuleDefinition is hidden and cannot be reactivated or edited', function () {
    $definition = ModuleDefinition::query()->create([
        'name' => 'Technical Reports',
        'code' => 'technical_reports',
        'program_area' => 'development',
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_SPECIALIZED,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
        'deadline_mode' => ModuleDefinition::DEADLINE_NONE,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)->get(route('module-definitions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('definitions', fn ($definitions): bool => collect($definitions)->where('code', 'technical_reports')->isEmpty()));

    $this->actingAs($this->user)
        ->patch(route('module-definitions.status', $definition))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->put(route('module-definitions.update', $definition), [
            'name' => 'Technical Reports',
            'program_area' => 'development',
            'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
            'deadline_mode' => ModuleDefinition::DEADLINE_NONE,
            'is_active' => true,
        ])
        ->assertNotFound();

    expect($definition->fresh()->is_active)->toBeTrue();
});
test('active navigation excludes retired Technical Reports', function () {
    ModuleDefinition::query()->create([
        'name' => 'Technical Reports',
        'code' => 'technical_reports',
        'program_area' => 'development',
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC,
        'module_type' => ModuleDefinition::TYPE_REGULAR_TARGET,
        'reporting_frequency' => 'quarterly',
        'deadline_mode' => ModuleDefinition::DEADLINE_NONE,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('genericModuleNavigation', fn ($items): bool => collect($items)->where('label', 'Technical Reports')->isEmpty()));
});