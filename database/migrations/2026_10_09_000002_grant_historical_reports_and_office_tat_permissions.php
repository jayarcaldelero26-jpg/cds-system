<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = collect([
            'historical-reports.view',
            'historical-reports.encode',
            'office-tat.view',
            'office-tat.configure',
        ])->mapWithKeys(fn (string $name) => [$name => Permission::findOrCreate($name, 'web')]);

        $viewPermission = Permission::query()->where('name', 'reports.view')->where('guard_name', 'web')->first();
        if ($viewPermission) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $viewPermission->id)->pluck('role_id');
            $viewRoles = Role::query()->whereIn('id', $roleIds)->get();
            foreach ($viewRoles as $role) {
                $this->grant($role, $permissions['office-tat.view']);
            }
        }

        $viewPermissionNames = [
            'technical-reports.view', 'bms.view', 'bams.view', 'imea.view',
            'aws.view', 'management-plans.view', 'reports.view',
        ];
        $viewPermissionIds = Permission::query()->where('guard_name', 'web')->whereIn('name', $viewPermissionNames)->pluck('id');
        $viewerRoleIds = DB::table('role_has_permissions')->whereIn('permission_id', $viewPermissionIds)->pluck('role_id')->unique();
        $adminRoleIds = Role::query()->whereIn('name', ['CDS Admin', 'Super Admin'])->pluck('id');
        foreach (Role::query()->whereIn('id', $viewerRoleIds->merge($adminRoleIds)->unique())->get() as $role) {
            $this->grant($role, $permissions['historical-reports.view']);
        }

        $createPermissionNames = [
            'technical-reports.create', 'bms.create', 'bams.create', 'imea.create',
            'aws.create', 'management-plans.create',
        ];
        $createPermissionIds = Permission::query()->where('guard_name', 'web')->whereIn('name', $createPermissionNames)->pluck('id');
        $encoderRoleIds = DB::table('role_has_permissions')->whereIn('permission_id', $createPermissionIds)->pluck('role_id')->unique()
            ->merge($adminRoleIds)->unique();
        foreach (Role::query()->whereIn('id', $encoderRoleIds)->get() as $role) {
            $this->grant($role, $permissions['historical-reports.view']);
            $this->grant($role, $permissions['historical-reports.encode']);
        }

        foreach (Role::query()->whereIn('name', ['CDS Admin', 'Super Admin'])->get() as $role) {
            $this->grant($role, $permissions['office-tat.configure']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = ['historical-reports.view', 'historical-reports.encode', 'office-tat.view', 'office-tat.configure'];
        $permissionIds = Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grant(Role $role, Permission $permission): void
    {
        $exists = DB::table('role_has_permissions')
            ->where('role_id', $role->getKey())
            ->where('permission_id', $permission->getKey())
            ->exists();
        if (! $exists) {
            DB::table('role_has_permissions')->insert([
                'role_id' => $role->getKey(),
                'permission_id' => $permission->getKey(),
            ]);
        }
    }
};
