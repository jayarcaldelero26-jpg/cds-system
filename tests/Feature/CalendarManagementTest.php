<?php

use App\Models\NonWorkingDay;
use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\BusinessCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
});

test('a reports viewer can open Calendar but cannot mutate non-working days', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    $this->actingAs($viewer)->get(route('business-calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Calendar/Index')->has('nonWorkingDays'));

    $this->actingAs($viewer)->post(route('compliance-alerts.non-working-days.store'), [
        'date' => '2026-08-26', 'name' => 'Unauthorized holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ])->assertForbidden();
});

test('an authorized calendar manager can create, edit, deactivate, and delete a configured day', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('compliance-alerts.manage', 'web'),
    ]);

    $this->actingAs($manager)->post(route('compliance-alerts.non-working-days.store'), [
        'date' => '2026-08-26', 'name' => 'Local Foundation Day', 'type' => NonWorkingDay::TYPE_LOCAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_DAVAO_ORIENTAL, 'reference' => 'Memo 1', 'remarks' => 'Annual event', 'is_active' => true,
    ])->assertRedirect()->assertSessionHas('success');

    $day = NonWorkingDay::query()->firstOrFail();
    expect($day->name)->toBe('Local Foundation Day')
        ->and(app(BusinessCalendarService::class)->isWorkingDay('2026-08-26'))->toBeFalse();

    $this->actingAs($manager)->put(route('compliance-alerts.non-working-days.update', $day), [
        'date' => '2026-08-26', 'name' => 'Updated Foundation Day', 'type' => NonWorkingDay::TYPE_OTHER,
        'scope' => NonWorkingDay::SCOPE_DAVAO_ORIENTAL, 'reference' => null, 'remarks' => null, 'is_active' => false,
    ])->assertRedirect()->assertSessionHas('success');

    expect($day->fresh()->name)->toBe('Updated Foundation Day')
        ->and($day->fresh()->is_active)->toBeFalse()
        ->and(app(BusinessCalendarService::class)->isWorkingDay('2026-08-26'))->toBeTrue();

    $this->actingAs($manager)->delete(route('compliance-alerts.non-working-days.destroy', $day))
        ->assertRedirect()->assertSessionHas('success');

    expect(NonWorkingDay::query()->find($day->id))->toBeNull();
});

test('duplicate configured dates for the same scope and location are rejected by the calendar endpoint', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo(Permission::findOrCreate('compliance-alerts.manage', 'web'));
    $payload = [
        'date' => '2026-08-26', 'name' => 'Holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ];

    $this->actingAs($manager)->post(route('compliance-alerts.non-working-days.store'), $payload)->assertRedirect();
    $this->actingAs($manager)->post(route('compliance-alerts.non-working-days.store'), $payload)
        ->assertSessionHasErrors('date');
});

test('calendar projects submitted reports by actual submission month and combines module and protected area filters', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
    ]);
    $otherUser = User::factory()->create();
    $area = ProtectedArea::query()->create(['name' => 'Mount Hamiguitan Range Wildlife Sanctuary', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $otherUser->id, 'updated_by' => $otherUser->id]);
    $otherArea = ProtectedArea::query()->create(['name' => 'Pujada Bay Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $otherUser->id, 'updated_by' => $otherUser->id]);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'activity_name' => 'BMS Report', 'date_accomplished' => '2026-07-30', 'date_received_penro' => '2026-08-20']);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'activity_name' => 'Outside Month', 'date_received_penro' => '2026-07-31']);
    BmsReportSubmission::query()->create(['protected_area_id' => $otherArea->id, 'semester' => '1st Semester', 'activity_name' => 'Other PA', 'date_received_penro' => '2026-08-21']);

    $this->actingAs($viewer)->get(route('business-calendar.index', ['month' => '2026-08', 'module' => 'bms', 'protected_area_id' => $area->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Calendar/Index')
            ->where('month', '2026-08')
            ->where('filters.module', 'bms')
            ->where('filters.protected_area_id', $area->id)
            ->has('movEvents', 1)
            ->where('movEvents.0.submission_date', '2026-08-20')
            ->where('movEvents.0.date_accomplished', '2026-07-30')
            ->where('movEvents.0.protected_area_name', $area->name));
});

test('calendar year view groups permitted submissions by month and respects protected area and module filters', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
        Permission::findOrCreate('bams.view', 'web'),
    ]);
    $owner = User::factory()->create();
    $area = ProtectedArea::query()->create(['name' => 'Mount Hamiguitan Range Wildlife Sanctuary', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    $otherArea = ProtectedArea::query()->create(['name' => 'Pujada Bay Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id]);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-20']);
    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '2nd Semester', 'date_received_penro' => '2026-09-10']);
    BamsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-21']);
    BmsReportSubmission::query()->create(['protected_area_id' => $otherArea->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-22']);
    NonWorkingDay::query()->create(['date' => '2026-08-21', 'name' => 'Foundation Day', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026, 'protected_area_id' => $area->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Calendar/Index')->where('view', 'year')->where('year', 2026)->has('yearSummary.months', 12)->where('yearSummary.months.08.submitted_movs', 2)->where('yearSummary.months.08.modules.0.key', 'bams')->where('yearSummary.months.08.modules.0.count', 1)->where('yearSummary.months.08.modules.1.key', 'bms')->where('yearSummary.months.08.modules.1.count', 1)->where('yearSummary.months.08.days.20.0.source_type', 'bms')->where('yearSummary.months.08.days.21.0.source_type', 'bams')->where('yearSummary.months.09.submitted_movs', 1)->where('yearSummary.overview.submitted_movs', 3)->where('yearSummary.overview.active_modules', 2)->where('yearSummary.overview.months_with_submissions', 2)->where('yearSummary.overview.non_working_days', 1));

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026, 'protected_area_id' => $area->id, 'module' => 'bms']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('yearSummary.months.08.submitted_movs', 1)->where('yearSummary.months.08.days.20.0.source_type', 'bms')->missing('yearSummary.months.08.days.21')->where('yearSummary.months.09.submitted_movs', 1)->where('yearSummary.overview.submitted_movs', 2)->where('yearSummary.overview.active_modules', 1));

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'month', 'month' => '2026-08', 'protected_area_id' => $area->id, 'module' => 'bms']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('view', 'month')->where('month', '2026-08')->where('filters.module', 'bms')->where('filters.protected_area_id', $area->id)->has('movEvents', 1));
});

test('calendar ENGP events and year summaries respect the development office scope', function (): void {
    $viewer = User::factory()->create([
        'unit_assignment' => 'development',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $viewer->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('technical-reports.view', 'web'),
    ]);

    $base = [
        'workflow_key' => 'cbep',
        'section_name' => 'NGP',
        'activity_name' => 'Community-Based Employment Program (CBEP)',
        'document_type' => 'Monthly Report',
        'reporting_year' => 2026,
        'period_key' => '2026-08',
        'period_label' => 'August 2026',
        'deadline_submission' => '2026-08-20',
        'date_received_penro' => '2026-08-18',
    ];
    EngpReportSubmission::create([...$base, 'office' => 'CENRO Baganga']);
    EngpReportSubmission::create([...$base, 'office' => 'CENRO Mati']);

    $this->actingAs($viewer)->get(route('business-calendar.index', ['month' => '2026-08', 'module' => 'engp']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movEvents', 1)
            ->where('movEvents.0.office', 'CENRO Baganga'));

    $this->actingAs($viewer)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026, 'module' => 'engp']))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('yearSummary.months.08.submitted_movs', 1)
            ->where('yearSummary.overview.submitted_movs', 1));
});

test('calendar month and year views scope PA events and options to the PAMO assignment', function (): void {
    $pamo = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'PAMO',
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    $pamo->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
    ]);

    $area = ProtectedArea::query()->create([
        'name' => 'PAMO Assigned Area', 'category' => 'National Park', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $pamo->id, 'updated_by' => $pamo->id,
    ]);
    $otherArea = ProtectedArea::query()->create([
        'name' => 'PAMO Unrelated Area', 'category' => 'Protected Landscape', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $pamo->id, 'updated_by' => $pamo->id,
    ]);
    $pamo->update(['protected_area_id' => $area->id]);

    BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-20']);
    BmsReportSubmission::query()->create(['protected_area_id' => $otherArea->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-21']);

    $this->actingAs($pamo)->get(route('business-calendar.index', ['month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movEvents', 1)
            ->where('movEvents.0.protected_area_id', $area->id)
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $area->id));

    $this->actingAs($pamo)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('yearSummary.overview.submitted_movs', 1)
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $area->id));

    $this->actingAs($pamo)->get(route('business-calendar.index', ['month' => '2026-08', 'protected_area_id' => $otherArea->id]))
        ->assertForbidden();
});

test('calendar PA sources scope CENRO events and options to supervised areas', function (): void {
    $cenro = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
    ]);
    $cenro->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'),
        Permission::findOrCreate('technical-reports.view', 'web'),
        Permission::findOrCreate('bms.view', 'web'),
    ]);
    $owner = User::factory()->create();
    $bagangaOffice = OrganizationalOffice::query()->where('name', 'CENRO Baganga')->firstOrFail();
    $matiOffice = OrganizationalOffice::query()->where('name', 'CENRO Mati')->firstOrFail();

    $supervised = ProtectedArea::query()->create([
        'name' => 'CENRO Supervised Area', 'category' => 'National Park', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    $unsupervised = ProtectedArea::query()->create([
        'name' => 'CENRO Unsupervised Area', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $supervised->id,
        'organizational_office_id' => $bagangaOffice->id,
        'assignment_type' => 'supervising',
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $unsupervised->id,
        'organizational_office_id' => $matiOffice->id,
        'assignment_type' => 'supervising',
    ]);

    BmsReportSubmission::query()->create(['protected_area_id' => $supervised->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-20']);
    BmsReportSubmission::query()->create(['protected_area_id' => $unsupervised->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-21']);
    ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $supervised->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Report', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-15', 'date_received_penro' => '2026-08-22',
    ]);
    ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $unsupervised->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Report', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-15', 'date_received_penro' => '2026-08-23',
    ]);

    $this->actingAs($cenro)->get(route('business-calendar.index', ['month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movEvents', 2)
            ->where('movEvents.0.protected_area_id', $supervised->id)
            ->where('movEvents.1.protected_area_id', $supervised->id)
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $supervised->id));

    $this->actingAs($cenro)->get(route('business-calendar.index', ['view' => 'year', 'year' => 2026]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('yearSummary.overview.submitted_movs', 2)
            ->has('protectedAreas', 1)
            ->where('protectedAreas.0.id', $supervised->id));

    $this->actingAs($cenro)->get(route('business-calendar.index', ['month' => '2026-08', 'protected_area_id' => $unsupervised->id]))
        ->assertForbidden();
});

test('PENRO and global calendar users retain broad PA visibility', function (): void {
    $owner = User::factory()->create();
    $areas = collect(['Calendar PENRO Area', 'Calendar Global Area'])->map(fn (string $name) => ProtectedArea::query()->create([
        'name' => $name, 'category' => 'National Park', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
        'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]));
    foreach ($areas as $area) {
        BmsReportSubmission::query()->create(['protected_area_id' => $area->id, 'semester' => '1st Semester', 'date_received_penro' => '2026-08-20']);
    }

    $penro = User::factory()->create([
        'unit_assignment' => 'conservation', 'section' => 'PENRO_CDS_FOCAL', 'office_designated' => 'PENRO Davao Oriental',
    ]);
    $penro->givePermissionTo([
        Permission::findOrCreate('reports.view', 'web'), Permission::findOrCreate('bms.view', 'web'),
    ]);
    $this->actingAs($penro)->get(route('business-calendar.index', ['month' => '2026-08', 'module' => 'bms']))
        ->assertInertia(fn ($page) => $page->has('movEvents', 2)->has('protectedAreas', 2));

    $adminRole = Role::findOrCreate('Super Admin', 'web');
    $adminRole->syncPermissions([
        Permission::findOrCreate('reports.view', 'web'), Permission::findOrCreate('bms.view', 'web'),
    ]);
    $admin = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $admin->assignRole($adminRole);
    $this->actingAs($admin)->get(route('business-calendar.index', ['month' => '2026-08', 'module' => 'bms']))
        ->assertInertia(fn ($page) => $page->has('movEvents', 2)->has('protectedAreas', 2));
});
