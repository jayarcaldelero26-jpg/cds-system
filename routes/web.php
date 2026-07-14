<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ProtectedAreaController;
use App\Http\Controllers\ManagementPlanController;
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
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

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

    Route::get('management-plans', [ManagementPlanController::class, 'index'])->middleware('can:management-plans.view')->name('management-plans.index');
    Route::get('management-plans/create', [ManagementPlanController::class, 'create'])->middleware('can:management-plans.create')->name('management-plans.create');
    Route::post('management-plans', [ManagementPlanController::class, 'store'])->middleware('can:management-plans.create')->name('management-plans.store');
    Route::get('management-plans/{managementPlan}/edit', [ManagementPlanController::class, 'edit'])->middleware('can:management-plans.update')->name('management-plans.edit');
    Route::patch('management-plans/{managementPlan}', [ManagementPlanController::class, 'update'])->middleware('can:management-plans.update')->name('management-plans.update');
    Route::delete('management-plans/{managementPlan}', [ManagementPlanController::class, 'destroy'])->middleware('can:management-plans.delete')->name('management-plans.destroy');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::resource('users', UserController::class)->except('show');
    });

require __DIR__.'/auth.php';
