<?php

use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    foreach (['imea.view', 'imea.create', 'imea.update', 'imea.delete'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

test('IMEA Report remains accessible while its local Data switch is absent', function () {
    $this->actingAs($this->user)
        ->get(route('imea.report-submissions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Imea/ReportSubmissions'));

    $reportPage = File::get(resource_path('js/Pages/Imea/ReportSubmissions.jsx'));
    expect($reportPage)->not->toContain("route('imea.index'")
        ->and($reportPage)->not->toContain('IMEA Data');
});

test('IMEA Data remains accessible from its existing route', function () {
    $this->actingAs($this->user)
        ->get(route('imea.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Imea/Index'));
});

test('IMEA Maintenance is preserved in storage but removed from active aggregators', function () {
    $area = ProtectedArea::create([
        'name' => 'Test IMEA Protected Area',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $record = ImeaFacilityMaintenanceReport::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Maintenance of Ecotourism Facilities',
        'document_type' => 'Progress Report',
        'quarter' => 'Quarter 1',
        'date_accomplished' => '2026-08-01',
    ]);

    expect(ImeaFacilityMaintenanceReport::query()->find($record->id))->not->toBeNull()
        ->and(app(SubmissionTrackingService::class)->source('imea-maintenance'))->toBeNull()
        ->and(app(OverdueReportService::class)->sourceDefinitions())->not->toHaveKey(ImeaFacilityMaintenanceReport::class)
        ->and(app(SubmissionTrackingService::class)->source('imea')['model'])->toBe(ImeaReportSubmission::class)
        ->and(app(OverdueReportService::class)->sourceDefinitions())->toHaveKey(ImeaReportSubmission::class);

    $tabs = File::get(resource_path('js/Pages/Imea/WorkflowTabs.jsx'));
    expect($tabs)->not->toContain('maintenance-reports')
        ->and($tabs)->not->toContain('Maintenance of Ecotourism Facilities Report');
});
