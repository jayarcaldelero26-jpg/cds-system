<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProtectedAreaController;
use App\Http\Controllers\ManagementPlanController;
use App\Http\Controllers\TechnicalReportController;
use App\Http\Controllers\EcotourismMonitoringController; // <-- GIDUGANG NGA IMPORT
use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard', [
        'protectedAreasCount' => ProtectedArea::query()->count(),
        'activeManagementPlansCount' => ManagementPlan::query()->where('status', 'Active')->count(),
        'expiredManagementPlansCount' => ManagementPlan::query()->where('status', 'Expired')->count(),
        'plansForUpdatingCount' => ManagementPlan::query()->where('status', 'For Updating')->count(),
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    // PROFILE ROUTES
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // PROTECTED AREAS ROUTES
    Route::get('protected-areas', [ProtectedAreaController::class, 'index'])
        ->middleware('can:protected-areas.view')
        ->name('protected-areas.index');
    Route::get('protected-areas/create', [ProtectedAreaController::class, 'create'])
        ->middleware('can:protected-areas.create')
        ->name('protected-areas.create');
    Route::post('protected-areas', [ProtectedAreaController::class, 'store'])
        ->middleware('can:protected-areas.create')
        ->name('protected-areas.store');
    Route::get('protected-areas/{protectedArea}/edit', [ProtectedAreaController::class, 'edit'])
        ->middleware('can:protected-areas.update')
        ->name('protected-areas.edit');
    Route::patch('protected-areas/{protectedArea}', [ProtectedAreaController::class, 'update'])
        ->middleware('can:protected-areas.update')
        ->name('protected-areas.update');
    Route::delete('protected-areas/{protectedArea}', [ProtectedAreaController::class, 'destroy'])
        ->middleware('can:protected-areas.delete')
        ->name('protected-areas.destroy');

    // ECOTOURISM IMPACT MONITORING ROUTES (GIDUGANG KINI NGA MGA ROUTE)
    Route::get('ecotourism-monitorings', [EcotourismMonitoringController::class, 'index'])->middleware('can:ecotourism-monitoring.view')->name('ecotourism-monitorings.index');
    Route::get('ecotourism-monitorings/create', [EcotourismMonitoringController::class, 'create'])->middleware('can:ecotourism-monitoring.create')->name('ecotourism-monitorings.create');
    Route::post('ecotourism-monitorings', [EcotourismMonitoringController::class, 'store'])->middleware('can:ecotourism-monitoring.create')->name('ecotourism-monitorings.store');
    Route::get('ecotourism-monitorings/{ecotourismMonitoring}/edit', [EcotourismMonitoringController::class, 'edit'])->middleware('can:ecotourism-monitoring.update')->name('ecotourism-monitorings.edit');
    Route::patch('ecotourism-monitorings/{ecotourismMonitoring}', [EcotourismMonitoringController::class, 'update'])->middleware('can:ecotourism-monitoring.update')->name('ecotourism-monitorings.update');
    Route::delete('ecotourism-monitorings/{ecotourismMonitoring}', [EcotourismMonitoringController::class, 'destroy'])->middleware('can:ecotourism-monitoring.delete')->name('ecotourism-monitorings.destroy');

    // MANAGEMENT PLANS ROUTES
    Route::get('management-plans', [ManagementPlanController::class, 'index'])->middleware('can:management-plans.view')->name('management-plans.index');
    Route::get('management-plans/create', [ManagementPlanController::class, 'create'])->middleware('can:management-plans.create')->name('management-plans.create');
    Route::post('management-plans', [ManagementPlanController::class, 'store'])->middleware('can:management-plans.create')->name('management-plans.store');
    Route::get('management-plans/{managementPlan}/edit', [ManagementPlanController::class, 'edit'])->middleware('can:management-plans.update')->name('management-plans.edit');
    Route::patch('management-plans/{managementPlan}', [ManagementPlanController::class, 'update'])->middleware('can:management-plans.update')->name('management-plans.update');
    Route::delete('management-plans/{managementPlan}', [ManagementPlanController::class, 'destroy'])->middleware('can:management-plans.delete')->name('management-plans.destroy');

    // TECHNICAL REPORTS / AWS ROUTES
    Route::get('technical-reports', [TechnicalReportController::class, 'index'])->middleware('can:technical-reports.view')->name('technical-reports.index');
    Route::get('technical-reports/create', [TechnicalReportController::class, 'create'])->middleware('can:technical-reports.create')->name('technical-reports.create');
    Route::post('technical-reports', [TechnicalReportController::class, 'store'])->middleware('can:technical-reports.create')->name('technical-reports.store');
    Route::get('technical-reports/{technicalReport}/edit', [TechnicalReportController::class, 'edit'])->middleware('can:technical-reports.update')->name('technical-reports.edit');
    Route::patch('technical-reports/{technicalReport}', [TechnicalReportController::class, 'update'])->middleware('can:technical-reports.update')->name('technical-reports.update');
    Route::delete('technical-reports/{technicalReport}', [TechnicalReportController::class, 'destroy'])->middleware('can:technical-reports.delete')->name('technical-reports.destroy');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::resource('users', UserController::class)->except('show');
    });

require __DIR__.'/auth.php';

// =========================================================================
// FIX & SEEDER: RECREATE ADMIN, GENERATE PERMISSIONS & ROLES (WITH ECOTOURISM & TECHNICAL REPORTS)
// =========================================================================
Route::get('/debug-admin', function () {
    try {
        // 1. Siguroha nga naa ang Roles (CDS Admin ug Staff)
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'CDS Admin', 'guard_name' => 'web']);
        $staffRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);

        // 2. I-generate ang tanang permissions (Lakip ang Technical Reports ug Ecotourism)
        $permissions = [
            'protected-areas.view',
            'protected-areas.create',
            'protected-areas.update',
            'protected-areas.delete',

            'management-plans.view',
            'management-plans.create',
            'management-plans.update',
            'management-plans.delete',

            'technical-reports.view',
            'technical-reports.create',
            'technical-reports.update',
            'technical-reports.delete',

            'ecotourism-monitoring.view',
            'ecotourism-monitoring.create',
            'ecotourism-monitoring.update',
            'ecotourism-monitoring.delete', // <-- GIDUGANG NGA ECOTOURISM PERMISSIONS
        ];

        foreach ($permissions as $permissionName) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web'
            ]);
        }

        // 3. I-assign ang tibuok permissions sa CDS ADMIN
        $adminRole->syncPermissions($permissions);

        // 4. I-assign lang ang VIEW, CREATE, ug UPDATE sa STAFF
        $staffPermissions = [
            'protected-areas.view',
            'protected-areas.create',
            'protected-areas.update',

            'management-plans.view',
            'management-plans.create',
            'management-plans.update',

            'technical-reports.view',
            'technical-reports.create',
            'technical-reports.update',

            'ecotourism-monitoring.view',
            'ecotourism-monitoring.create',
            'ecotourism-monitoring.update', // <-- GIDUGANG NGA STAFF ECOTOURISM PERMISSIONS
        ];
        $staffRole->syncPermissions($staffPermissions);

        // 5. I-update ang imong Admin account ug i-set nga active
        $user = \App\Models\User::updateOrCreate(
            ['email' => 'tempcdsims@gmail.com'],
            [
                'name' => 'Conservation Development Section',
                'password' => bcrypt('denrcds2026'),
                'is_active' => true,
            ]
        );

        $user->is_active = true;
        $user->save();

        $user->syncRoles([]);
        $user->assignRole($adminRole); // Gi-assign ang CDS Admin

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return "SUCCESS! Na-update ang Admin ngadto sa 'CDS Admin', na-set nga ACTIVE, ug andam na ang tanan lakip ang Technical Reports ug Ecotourism Monitoring.<br><br>
                Sulayi og refresh ang <b>/admin/users</b> karon!";

    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});

// ==================================================
// ROUTE PARA MAPUGOS UG VIEW ANG PDF UG PICTURES
// ==================================================
Route::get('/view-file/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);

    if (!file_exists($fullPath)) {
        abort(404);
    }

    return response()->file($fullPath);
})->where('path', '.*');
