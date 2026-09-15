<?php

use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::findOrCreate('CDS Admin', 'web');
    Role::findOrCreate('no_role', 'web');
});

test('business account roles are only User and Super Admin', function (): void {
    $organization = app(OrganizationalAccessService::class);

    expect($organization->accountRoleOptions())->toBe([
        ['value' => 'User', 'label' => 'User'],
        ['value' => 'Super Admin', 'label' => 'Super Admin'],
    ]);
});

test('the explicit bootstrap administrator is seeded approved and active', function (): void {
    putenv('EDATS_SEED_ADMIN_EMAIL=bootstrap-admin@example.test');
    putenv('EDATS_SEED_ADMIN_PASSWORD=Password123!');

    try {
        $this->seed(DatabaseSeeder::class);
    } finally {
        putenv('EDATS_SEED_ADMIN_EMAIL');
        putenv('EDATS_SEED_ADMIN_PASSWORD');
    }

    $admin = User::where('email', 'bootstrap-admin@example.test')->firstOrFail();

    expect($admin->is_approved)->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->hasRole('CDS Admin'))->toBeTrue();
});

test('supported operational groups contain every category once with PENRO TSD Chief in the PENRO group', function (): void {
    $groups = collect(app(OrganizationalAccessService::class)->operationalGroups());

    expect($groups->pluck('value')->all())->toBe(['cenro', 'penro'])
        ->and(collect($groups->firstWhere('value', 'penro')['categories'])->pluck('value')->all())
        ->toBe([
            'PENRO_RECORDS',
            'OFFICE_OF_THE_PENRO',
            'PENRO_TSD_CHIEF',
            'PENRO_CDS_FOCAL',
            'PENRO_CDS_CHIEF',
        ])
        ->and(collect($groups->firstWhere('value', 'cenro')['categories'])->pluck('value')->all())
        ->toBe(['CENRO_CDS_FOCAL', 'CENRO_CDS_CHIEF', 'CENRO_RECORDS']);
});

test('MHRWS remains direct PENRO while PBPLS remains CENRO Mati', function (): void {
    $admin = User::factory()->create();
    $mhrws = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI',
        'status' => 'Active', 'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    $pbpls = ProtectedArea::create([
        'name' => 'Pujada Bay Protected Landscape and Seascape (PBPLS)', 'short_name' => 'PBPLS',
        'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'status' => 'Active', 'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);

    $organization = app(OrganizationalAccessService::class);
    expect($organization->supervisingOfficeNameForProtectedArea($mhrws->id))->toBe('PENRO Davao Oriental')
        ->and($organization->supervisingOfficeNameForProtectedArea($pbpls->id))->toBe('CENRO Mati');
});

test('public registration rejects unsupported PAMO accounts', function (): void {
    $this->post('/register', [
        'name' => 'MHRWS PAMO Applicant', 'email' => 'architecture-mhrws@example.com',
        'operational_group' => 'pamo', 'section' => 'PAMO',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['operational_group', 'section']);

    expect(User::where('email', 'architecture-mhrws@example.com')->exists())->toBeFalse();
});

test('PENRO group registration derives canonical province scope without unit or PA assignment', function (): void {
    $this->post('/register', [
        'name' => 'PENRO Focal Applicant', 'email' => 'penro-focal@example.com',
        'operational_group' => 'penro', 'section' => 'PENRO_CDS_FOCAL',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('login'));

    $applicant = User::where('email', 'penro-focal@example.com')->firstOrFail();
    expect($applicant->office_designated)->toBe('PENRO Davao Oriental')
        ->and($applicant->unit_assignment)->toBeNull()
        ->and($applicant->protected_area_id)->toBeNull();
});

test('invalid group category and office combinations are rejected without broadening scope', function (): void {
    $organization = app(OrganizationalAccessService::class);

    expect(fn () => $organization->validateAssignment(
        OrganizationalAccessService::DEVELOPMENT,
        OrganizationalAccessService::CENRO_FOCAL,
        'CENRO Baganga',
        null,
        null,
        OrganizationalAccessService::OPERATIONAL_GROUP_PAMO,
    ))->toThrow(ValidationException::class);

    expect(fn () => $organization->validateAssignment(
        null,
        OrganizationalAccessService::CENRO_RECORDS,
        'CENRO Cateel',
        null,
        null,
        OrganizationalAccessService::OPERATIONAL_GROUP_CENRO,
    ))->toThrow(ValidationException::class);
});

test('registration and User Management use Operational Group rather than the retired assigned-unit label', function (): void {
    $registration = file_get_contents(resource_path('js/Pages/Auth/Register.jsx'));
    $userForm = file_get_contents(resource_path('js/Pages/Admin/Users/Form.jsx'));

    expect($registration)
        ->toContain('label="Operational Group"')
        ->toContain('Select the organizational group for the requested account.')
        ->not->toContain('Assigned unit (CENRO Focal only)')
        ->and($userForm)
        ->toContain('label="Operational Group"')
        ->toContain('operationalGroups')
        ->not->toContain('Assigned Unit');
});
