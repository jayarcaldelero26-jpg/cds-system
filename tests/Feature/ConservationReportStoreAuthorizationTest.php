<?php

use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
});

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

test('CENRO focal save derives its office when the create form omits target office', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(ConservationReportSubmission::query()->latest('id')->value('target_office'))
        ->toBe('CENRO Baganga');
});

test('CENRO focal cannot spoof another target office during save', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
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

    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
            'protected_area_id' => $area->id,
        ]))
        ->assertForbidden();

    expect(ConservationReportSubmission::query()->where('protected_area_id', $area->id)->exists())
        ->toBeFalse();
});
