<?php

use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Models\DocumentRoutingEvent;
use App\Models\BmsReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function progressActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'section' => $category,
        'office_designated' => $office,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    return $user;
}

function progressReport(string $name): BmsReportSubmission
{
    $creator = User::query()->firstOrFail();
    $area = ProtectedArea::create([
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 3)),
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id'),
    ]);
    return BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Progress report',
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);
}

function progressEngpReport(): EngpReportSubmission
{
    return EngpReportSubmission::create([
        'workflow_key' => 'weekly_accomplishment',
        'office' => 'CENRO Mati',
        'activity_name' => 'ENGP Progress Report',
        'document_type' => 'Report',
        'reporting_year' => 2026,
        'period_key' => '2026-w1',
        'period_label' => 'Week 1',
        'deadline_submission' => '2026-08-20',
    ]);
}

test('processing percentage is profile-aware for ENGP and extended generic routing', function (): void {
    $focal = progressActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = progressActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = progressActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = progressActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = progressActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = progressActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = progressActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = progressActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $presenter = app(DocumentRoutingPresenter::class);

    $engp = progressEngpReport();
    $engpSteps = [
        [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
        [$records, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
    ];
    expect($presenter->present($engp->fresh(), 'engp')['processing_percentage'])->toBe(0);
    DocumentRoutingEvent::create([
        'source_type' => 'engp', 'source_id' => $engp->id, 'workflow_key' => $engp->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
        'to_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS, 'from_office' => 'CENRO Records Unit',
        'to_office' => 'PENRO Records Unit', 'occurred_at' => now(), 'recorded_by' => $penroRecords->id,
    ]);
    $engpEvents = DocumentRoutingEvent::query()->where('source_type', 'engp')->where('source_id', $engp->id)->get();
    expect($presenter->present($engp->fresh(), 'engp', null, $engpEvents)['processing_percentage'])->toBe(100);

    $extended = progressReport('Extended Progress PA');
    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
        'to_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS, 'from_office' => 'CENRO Records Unit',
        'to_office' => 'PENRO Records Unit', 'occurred_at' => now(), 'recorded_by' => $penroRecords->id,
    ]);
    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(90);

    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'received', 'from_stage' => DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF,
        'to_stage' => DocumentRoutingProfileRegistry::CDS_CHIEF, 'from_office' => 'PENRO CDS Focal Person',
        'to_office' => 'PENRO CDS Chief', 'occurred_at' => now(), 'recorded_by' => $penroChief->id,
    ]);

    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(100);
    DocumentRoutingEvent::create([
        'source_type' => 'bms', 'source_id' => $extended->id, 'workflow_key' => $extended->workflow_key,
        'event_key' => 'recommended', 'from_stage' => DocumentRoutingProfileRegistry::CDS_CHIEF,
        'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN,
        'from_office' => 'PENRO CDS Chief', 'to_office' => 'Office of the PENRO',
        'occurred_at' => now(), 'recorded_by' => $penroChief->id,
    ]);
    $extendedEvents = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $extended->id)->get();
    expect($presenter->present($extended->fresh(), 'bms', null, $extendedEvents)['processing_percentage'])->toBe(100);
});

test('tracking detail exposes the profile-aware processing percentage and compact status marker', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));
    $css = file_get_contents(base_path('resources/css/app.css'));

    expect($tracking)
        ->toContain('aria-label="Processing progress"')
        ->toContain('edats-tracking-current-marker__pulse')
        ->toContain('processing_percentage')
        ->and($css)
        ->toContain('@keyframes edats-tracking-status-pulse')
        ->toContain('2.1s ease-out infinite')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});
