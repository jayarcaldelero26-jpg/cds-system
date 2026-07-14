<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Seed CDS permissions and assign them to the system roles.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [
                'records.view',
                'records.create',
                'records.update.assigned',
                'records.update.all',
                'records.delete',
                'operational-data.upload',
                'operational-data.manage',
            ])
            ->delete();

        $permissions = [
            // User Management
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.activate',
            'users.deactivate',

            // Access Control
            'roles.manage',
            'permissions.manage',

            // Audit
            'audit-logs.view',

            // Documents
            'documents.view',
            'documents.upload',
            'documents.update',
            'documents.delete',

            // Projects
            'projects.view',
            'projects.create',
            'projects.update',
            'projects.delete',

            // Activities
            'activities.view',
            'activities.create',
            'activities.update',
            'activities.delete',

            // Reports
            'reports.view',
            'reports.generate',
            'reports.export',

            // GIS
            'gis.view',
            'gis.manage',

            // Protected Area Management
            'protected-areas.view',
            'protected-areas.create',
            'protected-areas.update',
            'protected-areas.delete',

            // Management Plans
            'management-plans.view',
            'management-plans.create',
            'management-plans.update',
            'management-plans.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findByName('CDS Admin', 'web')->syncPermissions($permissions);

        Role::findByName('Technical Staff', 'web')->syncPermissions([
            'documents.view',
            'documents.upload',
            'documents.update',
            'projects.view',
            'projects.create',
            'projects.update',
            'activities.view',
            'activities.create',
            'activities.update',
            'reports.view',
            'reports.generate',
            'reports.export',
            'gis.view',
            'gis.manage',
            'protected-areas.view',
            'protected-areas.create',
            'protected-areas.update',
            'management-plans.view',
            'management-plans.create',
            'management-plans.update',
        ]);

        Role::findByName('Viewer', 'web')->syncPermissions([
            'documents.view',
            'projects.view',
            'activities.view',
            'reports.view',
            'gis.view',
            'protected-areas.view',
            'management-plans.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
