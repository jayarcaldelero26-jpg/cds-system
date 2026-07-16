<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProtectedAreaController;
use App\Http\Controllers\ManagementPlanController;
use App\Http\Controllers\TechnicalReportController;
use App\Http\Controllers\EcotourismMonitoringController;
use App\Http\Controllers\IssueMonitoringController;
use App\Http\Controllers\LawinMonitoringController;
use App\Http\Controllers\ProgramProjectActivityController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\GlobalSearchController;
use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use App\Models\ProgramProjectActivity;
use App\Models\IssueMonitoring;
use App\Models\LawinMonitoring;
use App\Models\TechnicalReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth; // <-- GIDUGANG KINI NGA IMPORT PARA SA AUTHENTICATION
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/dashboard', function () {
    // 1. Pagkuha sa tinuod nga dynamic counts
    $protectedAreasCount = ProtectedArea::count();
    $activeManagementPlansCount = ManagementPlan::where('status', 'Active')->count();
    $expiredManagementPlansCount = ManagementPlan::where('status', 'Expired')->count();
    $plansForUpdatingCount = ManagementPlan::where('status', 'For Update')->count();
    $ppaCount = ProgramProjectActivity::count();
    $issueCount = IssueMonitoring::count();
    $lawinCount = LawinMonitoring::count();
    $technicalReportsCount = TechnicalReport::count();

    // 2. MAG-GENERATE OG TINUOD NGA RECENT ACTIVITIES GIKAN SA DATABASE
    $recentPpas = ProgramProjectActivity::latest()->take(2)->get()->map(function ($item) {
        return [
            'id' => 'ppa-' . $item->id,
            'activity' => "New PPA recorded: " . $item->title,
            'module' => 'PPA Projects',
            'date' => $item->created_at->diffForHumans(),
            'status' => 'Completed',
        ];
    });

    $recentLawins = LawinMonitoring::latest()->take(2)->get()->map(function ($item) {
        return [
            'id' => 'lawin-' . $item->id,
            'activity' => "Patrol conducted at " . ($item->patrol_area ?? 'Protected Area'),
            'module' => 'LAWIN',
            'date' => $item->created_at->diffForHumans(),
            'status' => 'Completed',
        ];
    });

    $recentIssues = IssueMonitoring::latest()->take(2)->get()->map(function ($item) {
        return [
            'id' => 'issue-' . $item->id,
            'activity' => "Threat reported: " . $item->threat_type,
            'module' => 'Issues',
            'date' => $item->created_at->diffForHumans(),
            'status' => $item->status === 'Resolved' ? 'Completed' : 'Pending Review',
        ];
    });

    // Isagol ang tanang nakuha nga activities ug i-sort pinaagi sa pinaka-bag-o
    $dbActivities = collect()
        ->merge($recentPpas)
        ->merge($recentLawins)
        ->merge($recentIssues)
        ->take(4) // Ipakita lang ang top 4 pinaka-bag-o
        ->values()
        ->toArray();

    return Inertia::render('Dashboard', [
        'protectedAreasCount' => $protectedAreasCount,
        'activeManagementPlansCount' => $activeManagementPlansCount,
        'expiredManagementPlansCount' => $expiredManagementPlansCount,
        'plansForUpdatingCount' => $plansForUpdatingCount,
        'ppaCount' => $ppaCount,
        'issueCount' => $issueCount,
        'lawinCount' => $lawinCount,
        'technicalReportsCount' => $technicalReportsCount,
        'dbActivities' => $dbActivities, // dynamic activities gikan sa DB!
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
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('can:reports.view')->name('reports.index');
    // ECOTOURISM IMPACT MONITORING ROUTES
    Route::get('ecotourism-monitorings', [EcotourismMonitoringController::class, 'index'])->middleware('can:ecotourism-monitoring.view')->name('ecotourism-monitorings.index');
    Route::get('ecotourism-monitorings/create', [EcotourismMonitoringController::class, 'create'])->middleware('can:ecotourism-monitoring.create')->name('ecotourism-monitorings.create');
    Route::post('ecotourism-monitorings', [EcotourismMonitoringController::class, 'store'])->middleware('can:ecotourism-monitoring.create')->name('ecotourism-monitorings.store');
    Route::get('ecotourism-monitorings/{ecotourismMonitoring}/edit', [EcotourismMonitoringController::class, 'edit'])->middleware('can:ecotourism-monitoring.update')->name('ecotourism-monitorings.edit');
    Route::patch('ecotourism-monitorings/{ecotourismMonitoring}', [EcotourismMonitoringController::class, 'update'])->middleware('can:ecotourism-monitoring.update')->name('ecotourism-monitorings.update');
    Route::delete('ecotourism-monitorings/{ecotourismMonitoring}', [EcotourismMonitoringController::class, 'destroy'])->middleware('can:ecotourism-monitoring.delete')->name('ecotourism-monitorings.destroy');

    // ISSUES MONITORING ROUTES
    Route::get('issue-monitorings', [IssueMonitoringController::class, 'index'])->middleware('can:issue-monitoring.view')->name('issue-monitorings.index');
    Route::get('issue-monitorings/create', [IssueMonitoringController::class, 'create'])->middleware('can:issue-monitoring.create')->name('issue-monitorings.create');
    Route::post('issue-monitorings', [IssueMonitoringController::class, 'store'])->middleware('can:issue-monitoring.create')->name('issue-monitorings.store');
    Route::get('issue-monitorings/{issueMonitoring}/edit', [IssueMonitoringController::class, 'edit'])->middleware('can:issue-monitoring.update')->name('issue-monitorings.edit');
    Route::patch('issue-monitorings/{issueMonitoring}', [IssueMonitoringController::class, 'update'])->middleware('can:issue-monitoring.update')->name('issue-monitorings.update');
    Route::delete('issue-monitorings/{issueMonitoring}', [IssueMonitoringController::class, 'destroy'])->middleware('can:issue-monitoring.delete')->name('issue-monitorings.destroy');

    // LAWIN MONITORING ROUTES
    Route::get('lawin-monitorings', [LawinMonitoringController::class, 'index'])->middleware('can:lawin-monitoring.view')->name('lawin-monitorings.index');
    Route::get('lawin-monitorings/create', [LawinMonitoringController::class, 'create'])->middleware('can:lawin-monitoring.create')->name('lawin-monitorings.create');
    Route::post('lawin-monitorings', [LawinMonitoringController::class, 'store'])->middleware('can:lawin-monitoring.create')->name('lawin-monitorings.store');
    Route::get('lawin-monitorings/{lawinMonitoring}/edit', [LawinMonitoringController::class, 'edit'])->middleware('can:lawin-monitoring.update')->name('lawin-monitorings.edit');
    Route::patch('lawin-monitorings/{lawinMonitoring}', [LawinMonitoringController::class, 'update'])->middleware('can:lawin-monitoring.update')->name('lawin-monitorings.update');
    Route::delete('lawin-monitorings/{lawinMonitoring}', [LawinMonitoringController::class, 'destroy'])->middleware('can:lawin-monitoring.delete')->name('lawin-monitorings.destroy');

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

    // PROGRAMS, PROJECTS & ACTIVITIES (PPA) ROUTES
    Route::get('program-project-activities', [ProgramProjectActivityController::class, 'index'])->middleware('can:programs-projects-activities.view')->name('program-project-activities.index');
    Route::get('program-project-activities/create', [ProgramProjectActivityController::class, 'create'])->middleware('can:programs-projects-activities.create')->name('program-project-activities.create');
    Route::post('program-project-activities', [ProgramProjectActivityController::class, 'store'])->middleware('can:programs-projects-activities.create')->name('program-project-activities.store');
    Route::get('program-project-activities/{programProjectActivity}/edit', [ProgramProjectActivityController::class, 'edit'])->middleware('can:programs-projects-activities.update')->name('program-project-activities.edit');
    Route::post('program-project-activities/{programProjectActivity}', [ProgramProjectActivityController::class, 'update'])->middleware('can:programs-projects-activities.update')->name('program-project-activities.update');
    Route::delete('program-project-activities/{programProjectActivity}', [ProgramProjectActivityController::class, 'destroy'])->middleware('can:programs-projects-activities.delete')->name('program-project-activities.destroy');

    Route::get('api/global-search', [GlobalSearchController::class, 'search'])->name('api.global-search');
    });

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::resource('users', UserController::class)->except('show');
    });

require __DIR__.'/auth.php';

// =========================================================================
// FIX & SEEDER: RECREATE ADMIN, GENERATE PERMISSIONS & ROLES (FOR ALL USERS)
// =========================================================================
Route::get('/debug-admin', function () {
    try {
        // 1. Siguroha nga naa ang Roles (CDS Admin ug Staff)
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'CDS Admin', 'guard_name' => 'web']);
        $staffRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'web']);

        // 2. I-generate ang tanang permissions (Lakip ang Reports ug PPAs)
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
            'ecotourism-monitoring.delete',

            'issue-monitoring.view',
            'issue-monitoring.create',
            'issue-monitoring.update',
            'issue-monitoring.delete',

            'lawin-monitoring.view',
            'lawin-monitoring.create',
            'lawin-monitoring.update',
            'lawin-monitoring.delete',

            'programs-projects-activities.view',
            'programs-projects-activities.create',
            'programs-projects-activities.update',
            'programs-projects-activities.delete',

            'reports.view', // <-- GIDUGANG KINI PARA SA REPORTS MODULE!
        ];

        foreach ($permissions as $permissionName) {
            \Spatie\Permission\Models\Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web'
            ]);
        }

        // 3. I-assign ang TIBUOK permissions sa CDS ADMIN (Makahimo og tanan lakip ang DELETE)
        $adminRole->syncPermissions($permissions);

        // 4. I-assign ang VIEW, CREATE, ug UPDATE LANG sa STAFF (WALAY DELETE PERMISSIONS APIL!)
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
            'ecotourism-monitoring.update',

            'issue-monitoring.view',
            'issue-monitoring.create',
            'issue-monitoring.update',

            'lawin-monitoring.view',
            'lawin-monitoring.create',
            'lawin-monitoring.update',

            'programs-projects-activities.view',
            'programs-projects-activities.create',
            'programs-projects-activities.update',
        ];
        // Kani nga code magsiguro nga malimpyo ug ma-override ang karaan nga setup sa Staff
        $staffRole->syncPermissions($staffPermissions);

        // 5. I-update ang imong Default Admin account ug i-set nga active
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
        $user->assignRole($adminRole); // Gi-assign ang CDS Admin sa default system email

        // 6. I-update ang kasamtangang user kon kinsa man ang naka-login karon
        if (Auth::check()) {
            $currentUser = \App\Models\User::find(Auth::id());
            if ($currentUser) {
                // I-assign ang husto nga role base sa email o default
                if ($currentUser->email === 'tempcdsims@gmail.com') {
                    $currentUser->syncRoles([]);
                    $currentUser->assignRole($adminRole);
                } else {
                    // Kon ordinaryong trabahante, i-re-sync iyang permissions isip Staff
                    $currentUser->syncRoles([]);
                    $currentUser->assignRole($staffRole);
                }
            }
        }

        // Limpyohan ang tanang cache sa Spatie
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return "SUCCESS! Na-update ang tanang roles.<br><br>
                * Ang <b>CDS Admin</b> makahimo na sa tanan lakip na ang <b>Delete</b> ug <b>Reports View</b>.<br>
                * Ang <b>Staff</b> makahimo na sa <b>View, Create, ug Update</b> apan gibalibaran sa <b>Delete</b> ug <b>Reports View</b>.<br><br>
                Tungod niini, dili na makadelete ang Staff!";

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
