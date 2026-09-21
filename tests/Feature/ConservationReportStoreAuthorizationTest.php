<?php

use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
        'protected_area_id' => null,
    ]);

    $role = Role::findOrCreate('CENRO CDS Focal Person', 'web');
    $role->syncPermissions([
        Permission::findOrCreate('technical-reports.view', 'web'),
        Permission::findOrCreate('technical-reports.create', 'web'),
        Permission::findOrCreate('technical-reports.update', 'web'),
    ]);
    $this->user->assignRole($role);
    $this->area = ProtectedArea::create([
        'name' => 'Baganga Scoped Area', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $officeId = DB::table('organizational_offices')->where('code', 'cenro_baganga')->value('id');
    DB::table('protected_area_office_assignments')->insert(['protected_area_id' => $this->area->id, 'organizational_office_id' => $officeId, 'assignment_type' => 'supervising', 'assigned_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);});

function focalRegularPambPayload(array $overrides = []): array
{
    return array_merge([
        'protected_area_id' => null,
        'target_office' => '',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-24',
        'date_accomplished' => '2026-08-24',
        'mov' => UploadedFile::fake()->create('regular-pamb.pdf', 10, 'application/pdf'),
    ], $overrides);
}

test('CENRO focal rejects a create form that omits target office', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload(['protected_area_id' => $this->area->id]))
        ->assertSessionHasErrors('target_office')
        ->assertSessionHasErrors('target_office');

// removed obsolete persistence expectation

});

test('CENRO focal cannot spoof another target office during save', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
            'protected_area_id' => $this->area->id,
            'target_office' => 'CENRO Mati',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(ConservationReportSubmission::query()->latest('id')->value('target_office'))
        ->toBe('CENRO Baganga');
});

test('CENRO focal still reaches the regular PAMB page with its own office scope', function (): void {
    $this->actingAs($this->user)
        ->get(route('conservation-reports.index', 'regular_pamb'))
        ->assertOk();
});

test('CENRO focal cannot create a submission for a PENRO-managed protected area', function (): void {
    $area = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $penroOfficeId = DB::table('organizational_offices')->where('code', 'penro_davao_oriental')->value('id');
    DB::table('protected_area_office_assignments')->insert(['protected_area_id' => $area->id, 'organizational_office_id' => $penroOfficeId, 'assignment_type' => 'supervising', 'assigned_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
            'protected_area_id' => $area->id,
            'target_office' => 'CENRO Baganga',
        ]))
        ->assertForbidden();

    expect(ConservationReportSubmission::query()->where('protected_area_id', $area->id)->exists())
        ->toBeFalse();
});
