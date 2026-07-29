<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tawgon una ang PermissionSeeder aron mabuhat ang tanang permissions ug roles
        $this->call([
            PermissionSeeder::class,
        ]);

        // 2. Siguraduhon nga naa ang basic roles
        $adminRole = Role::firstOrCreate(['name' => 'CDS Admin', 'guard_name' => 'web']);
        $staffRole = Role::firstOrCreate(['name' => 'Technical Staff', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'no_role', 'guard_name' => 'web']);

        // 3. Sigurohon nga naa ang Admin user ug i-assign ang 'CDS Admin' role
        $admin = User::where('email', 'tempcdsims@gmail.com')->first();
        if (!$admin) {
            $admin = User::create([
                'name' => 'Conservation Development Section',
                'email' => 'tempcdsims@gmail.com',
                'password' => bcrypt('denrcds2026'),
                'office_designated' => 'PENRO Davao Oriental',
                'section' => 'CDS',
                'is_active' => true,
            ]);
        }

        if (!$admin->hasRole('CDS Admin')) {
            $admin->assignRole($adminRole);
        }

        // 💡 4. ILAGAY DINHI ANG EMAIL SA IMONG MGA STAFF
        // I-uncomment ug ilisi ang 'staff@gmail.com' sa tinuod nilang gi-register nga email
        $staffEmails = [
            // 'ang_staff_email_nimo@gmail.com',
        ];

        foreach ($staffEmails as $email) {
            $staffUser = User::where('email', $email)->first();
            if ($staffUser && !$staffUser->hasRole('Technical Staff')) {
                $staffUser->assignRole($staffRole);
            }
        }

        // 5. Maghimo og sample Protected Areas
        $mpl = ProtectedArea::firstOrCreate(
            ['name' => 'Mati Protected Landscape (MPL)'],
            [
                'short_name' => 'MPL',
                'category' => 'Protected Landscape',
                'municipality' => 'Mati City',
                'province' => 'Davao Oriental',
                'region' => 'Region XI',
                'area_hectares' => 914.26,
                'pamo' => 'PAMO-MPL',
                'pasu' => 'Protected Area Superintendent MPL',
                'year_established' => 1998,
                'legal_basis' => 'Proclamation No. 123',
                'status' => 'Active',
                'description' => 'Protected landscape located in Mati City.',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        $mhrws = ProtectedArea::firstOrCreate(
            ['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)'],
            [
                'short_name' => 'MHRWS',
                'category' => 'Wildlife Sanctuary',
                'municipality' => 'San Isidro, Governor Generoso, Mati City',
                'province' => 'Davao Oriental',
                'region' => 'Region XI',
                'area_hectares' => 6834.00,
                'pamo' => 'PAMO-MHRWS',
                'pasu' => 'Protected Area Superintendent MHRWS',
                'year_established' => 2004,
                'legal_basis' => 'Republic Act No. 9303',
                'status' => 'Active',
                'description' => 'UNESCO World Heritage Site in Davao Oriental.',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        $pbpls = ProtectedArea::firstOrCreate(
            ['name' => 'Pujada Bay Protected Landscape and Seascape (PBPLS)'],
            [
                'short_name' => 'PBPLS',
                'category' => 'Protected Landscape and Seascape',
                'municipality' => 'Mati City',
                'province' => 'Davao Oriental',
                'region' => 'Region XI',
                'area_hectares' => 21200.00,
                'pamo' => 'PAMO-PBPLS',
                'pasu' => 'Protected Area Superintendent PBPLS',
                'year_established' => 1994,
                'legal_basis' => 'Proclamation No. 431',
                'status' => 'Active',
                'description' => 'Protected bay in Mati, Davao Oriental.',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );

        // 6. Maghimo og sample Management Plans para sa MPL
        ManagementPlan::create([
            'protected_area_id' => $mpl->id,
            'plan_type' => 'PAMP',
            'title' => 'Mati Protected Landscape Management Plan 2026',
            'version' => 'v1.0',
            'prepared_year' => 2026,
            'approval_date' => now(),
            'valid_from' => now(),
            'valid_until' => now()->addYears(5),
            'status' => 'Active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        // 7. Maghimo og sample Management Plans para sa MHRWS
        $plans = ['PAMP', 'EMP', 'CEPA'];
        foreach ($plans as $type) {
            ManagementPlan::create([
                'protected_area_id' => $mhrws->id,
                'plan_type' => $type,
                'title' => "Mt. Hamiguitan {$type} Implementation Plan",
                'version' => 'v2.1',
                'prepared_year' => 2026,
                'approval_date' => now(),
                'valid_from' => now(),
                'valid_until' => now()->addYears(5),
                'status' => 'Active',
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        }
    }
}
