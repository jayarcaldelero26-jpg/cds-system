<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Seed the CDS system roles.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['CDS Admin', 'Super Admin', 'CENRO Records Unit', 'CENRO CDS Chief', 'CENRO CDS Focal Person', 'PENRO Records Unit', 'PENRO CDS Chief', 'PENRO CDS Focal Person', 'PAMO', 'no_role'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
