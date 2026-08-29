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
            'compliance-alerts.manage',

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

        $technicalStaff = Role::firstOrCreate(['name' => 'Technical Staff', 'guard_name' => 'web']);
        $technicalStaff->syncPermissions([
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
            'compliance-alerts.manage',
            'technical-reports.view',
            'technical-reports.create',
            'technical-reports.update',
            'gis.view',
            'gis.manage',
            'protected-areas.view',
            'protected-areas.create',
            'protected-areas.update',
            'management-plans.view',
            'management-plans.create',
            'management-plans.update',
            'cds-lawin.view',
            'cds-lawin.create',
            'cds-lawin.update',
            'bms.view',
            'bms.create',
            'bms.update',
            'bams.view',
            'bams.create',
            'bams.update',
            'bams.calculate',
            'imea.view',
            'imea.create',
            'imea.update',
            'imea.import',
            'imea.export',
            'aws.view',
            'aws.create',
            'aws.update',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions([
            'documents.view',
            'projects.view',
            'activities.view',
            'reports.view',
            'technical-reports.view',
            'gis.view',
            'protected-areas.view',
            'management-plans.view',
            'cds-lawin.view',
            'bms.view',
            'bams.view',
            'imea.view',
            'aws.view',
        ]);

        // Gidugang usab nato ang 'no_role' aron dili ma-error ang pag-register sa mga users
        Role::firstOrCreate(['name' => 'no_role', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
