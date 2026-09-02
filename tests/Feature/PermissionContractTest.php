<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('the permission seeder provisions the technical report contract for operational categories', function () {
    $this->seed(PermissionSeeder::class);

    $abilities = [
        'technical-reports.view',
        'technical-reports.create',
        'technical-reports.update',
        'technical-reports.delete',
    ];

    expect(Permission::query()->whereIn('name', $abilities)->where('guard_name', 'web')->count())->toBe(4);

    $admin = Role::findByName('CDS Admin');
    $focal = Role::findByName('CENRO CDS Focal Person');
    $chief = Role::findByName('CENRO CDS Chief');
    $noRole = Role::findByName('no_role');

    expect(collect($abilities)->every(fn (string $ability): bool => $admin->hasPermissionTo($ability)))->toBeTrue()
        ->and(collect(['technical-reports.view', 'technical-reports.create', 'technical-reports.update'])->every(fn (string $ability): bool => $focal->hasPermissionTo($ability)))->toBeTrue()
        ->and($focal->hasPermissionTo('technical-reports.delete'))->toBeFalse()
        ->and(collect(['technical-reports.view', 'technical-reports.create', 'technical-reports.update'])->every(fn (string $ability): bool => $chief->hasPermissionTo($ability)))->toBeTrue()
        ->and($noRole->permissions()->whereIn('name', $abilities)->count())->toBe(0);
});

test('shared Inertia technical report authorization follows named abilities instead of role names', function () {
    $this->seed(PermissionSeeder::class);

    $staff = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => 'conservation', 'office_designated' => 'CENRO Baganga']);
    $staff->assignRole('CENRO CDS Focal Person');

    $this->actingAs($staff)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.canViewTechnicalReports', true)
            ->where('auth.canCreateTechnicalReports', true)
            ->where('auth.canUpdateTechnicalReports', true)
            ->where('auth.canDeleteTechnicalReports', false));

    $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $role->syncPermissions([]);
    $roleNamedAdmin = User::factory()->create(['section' => 'CDS']);
    $roleNamedAdmin->assignRole($role);

    $this->actingAs($roleNamedAdmin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.canViewTechnicalReports', false)
            ->where('auth.canCreateTechnicalReports', false)
            ->where('auth.canUpdateTechnicalReports', false)
            ->where('auth.canDeleteTechnicalReports', false));
});
