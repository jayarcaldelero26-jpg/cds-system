<?php

use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Role::findOrCreate('no_role', 'web');
});

function accessMatrixUser(string $category, ?string $unit = null, string $office = 'CENRO Baganga'): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'section' => $category,
        'unit_assignment' => $unit,
        'office_designated' => $office,
        'protected_area_id' => null,
    ]);
    $user->assignRole('no_role');

    return $user;
}

test('authoritative categories expose the TSD chief and no Technical Staff category', function (): void {
    $categories = collect(app(OrganizationalAccessService::class)->operationalGroups())
        ->firstWhere('value', OrganizationalAccessService::OPERATIONAL_GROUP_PENRO)['categories'];
    $categories = collect($categories)->pluck('value')->all();

    expect($categories)->toContain(OrganizationalAccessService::PENRO_TSD_CHIEF)
        ->not->toContain('Technical Staff');
});

test('CENRO focal and chief derive both units', function (): void {
    $organization = app(OrganizationalAccessService::class);
    $focal = accessMatrixUser(OrganizationalAccessService::CENRO_FOCAL);
    $chief = accessMatrixUser(OrganizationalAccessService::CENRO_CHIEF);

    expect($organization->effectiveUnits($focal))->toBe([OrganizationalAccessService::CONSERVATION, OrganizationalAccessService::DEVELOPMENT])
        ->and($organization->effectiveUnits($chief))->toBe([OrganizationalAccessService::CONSERVATION, OrganizationalAccessService::DEVELOPMENT])
        ->and($organization->canViewConservationModules($focal))->toBeTrue()
        ->and($organization->canViewDevelopmentModules($focal))->toBeTrue()
        ->and($organization->canViewConservationModules($chief))->toBeTrue()
        ->and($organization->canViewDevelopmentModules($chief))->toBeTrue();
});

test('routing-only categories receive tracking scope but no module browse capability', function (): void {
    $organization = app(OrganizationalAccessService::class);
    $records = accessMatrixUser(OrganizationalAccessService::CENRO_RECORDS);
    $penro = accessMatrixUser(OrganizationalAccessService::PENRO_RECORDS, null, 'PENRO Davao Oriental');

    expect($organization->canViewSubmissionTracking($records))->toBeTrue()
        ->and($organization->canViewConservationModules($records))->toBeFalse()
        ->and($organization->canViewDevelopmentModules($records))->toBeFalse()
        ->and($organization->effectiveUnits($penro))->toBe([OrganizationalAccessService::CONSERVATION, OrganizationalAccessService::DEVELOPMENT]);
});

test('invalid unit and category combinations are rejected server-side', function (): void {
    $organization = app(OrganizationalAccessService::class);

    expect(fn () => $organization->validateAssignment(
        null,
        OrganizationalAccessService::CENRO_FOCAL,
        'CENRO Baganga',
        null,
    ))->not->toThrow(ValidationException::class);

    expect(fn () => $organization->validateAssignment(
        OrganizationalAccessService::CONSERVATION,
        OrganizationalAccessService::PENRO_RECORDS,
        'PENRO Davao Oriental',
        null,
    ))->toThrow(ValidationException::class);

    expect(fn () => $organization->validateAssignment(
        null,
        OrganizationalAccessService::CENRO_RECORDS,
        'CENRO Cateel',
        null,
    ))->toThrow(ValidationException::class);
});
