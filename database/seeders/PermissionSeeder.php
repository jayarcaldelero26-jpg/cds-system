<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Services\Authorization\OrganizationalAccessService;

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

            // Module Registry
            'module-definitions.view',
            'module-definitions.create',
            'module-definitions.update',
            'module-definitions.activate',

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
            'compliance-alerts.manage',
            'submission-tracking.correct-routing',

            // Technical / general report submissions
            'technical-reports.view',
            'technical-reports.create',
            'technical-reports.update',
            'technical-reports.delete',

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

            // CDS Lawin Monitoring
            'cds-lawin.view',
            'cds-lawin.create',
            'cds-lawin.update',
            'cds-lawin.delete',

            // Biodiversity Monitoring System (BMS)
            'bms.view',
            'bms.create',
            'bms.update',
            'bms.delete',

            // Biodiversity Assessment and Monitoring System (BAMS)
            'bams.view',
            'bams.create',
            'bams.update',
            'bams.delete',
            'bams.manage-spatial',
            'bams.calculate',

            // Integrated Management Effectiveness Assessment (IMEA)
            'imea.view',
            'imea.create',
            'imea.update',
            'imea.delete',
            'imea.import',
            'imea.export',

            // Automated Weather Station (AWS)
            'aws.view',
            'aws.create',
            'aws.update',
            'aws.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 🚀 Gamiton ang firstOrCreate aron awtomatikong mahimo ang role kung wala pa
        $cdsAdmin = Role::firstOrCreate(['name' => 'CDS Admin', 'guard_name' => 'web']);
        $cdsAdmin->syncPermissions($permissions);

        // Technical Staff and Viewer were legacy user-facing categories. Their
        // existing DB rows are intentionally preserved, but new installations
        // no longer recreate them as active assignment profiles.

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions($permissions);

        $organization = app(OrganizationalAccessService::class);
        foreach ($organization->operationalCategories() as $category) {
            $internalRole = $organization->roleForCategory($category);
            Role::firstOrCreate(['name' => $internalRole, 'guard_name' => 'web'])
                ->syncPermissions($organization->permissionProfileForCategory($category));
        }

        // Gidugang usab nato ang 'no_role' aron dili ma-error ang pag-register sa mga users
        Role::firstOrCreate(['name' => 'no_role', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
