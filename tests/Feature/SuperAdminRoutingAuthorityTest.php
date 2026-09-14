<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Role;

function superAdminRoutingUser(): User
{
    $user = User::factory()->create(['section' => 'CDS', 'unit_assignment' => 'conservation']);
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
    return $user;
}

function superAdminRoutingArea(User $owner, string $name = 'Super Admin Scope PA'): ProtectedArea
{
    $area = ProtectedArea::create([
        'name' => $name, 'short_name' => 'SAP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);
    return $area;
}

function superAdminOperationalActor(string $section): User
{
    return User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => $section,
        'office_designated' => 'PENRO Davao Oriental',
    ]);
}

test('Super Admin retains global visibility but has no executable generic routing actions', function (): void {
    $admin = superAdminRoutingUser();
    $area = superAdminRoutingArea($admin);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Global visibility report', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);
    $access = app(DocumentRoutingAccessService::class);
    $registry = app(DocumentRoutingProfileRegistry::class);
    $profile = $registry->actionProfile('bms');
    $service = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);

    expect($access->canView($admin, $report, 'bms', 'bms.update'))->toBeTrue();
    foreach ($profile['actions'] as $action) {
        expect($access->canPerform($admin, $report, 'bms', $action, 'bms.update'))->toBeFalse();
    }

    $this->actingAs($admin)->get(route('submission-tracking.index'))->assertOk();
    $this->actingAs($admin)->post(route('submission-tracking.transition', ['bms', $report->id, 'forward_to_cenro_chief']), [
        'stage' => 'forward_to_cenro_chief',
    ])->assertForbidden();

    expect($service->events($report, 'bms'))->toBeEmpty()
        ->and($service->presentation($report, 'bms', null, $admin)['allowed_actions'])->toBeEmpty()
        ->and(app(\App\Services\SubmissionTracking\DocumentRoutingPresenter::class)->present($report, 'bms', collect(), collect())['next_expected_action'])->toBe('Forward to CENRO Chief');
});

test('Super Admin cannot perform PAMB normal actions or canonical transitions', function (): void {
    $admin = superAdminRoutingUser();
    $area = superAdminRoutingArea($admin, 'Super Admin PAMB PA');
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Global PAMB visibility',
        'target_office' => 'CENRO Mati', 'protected_area_id' => $area->id,
        'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04',
        'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    $access = app(PambSubmissionAccessService::class);

    expect($access->canView($admin, $report))->toBeTrue()
        ->and($access->canPerform($admin, 'submit'))->toBeFalse()
        ->and($access->canPerform($admin, 'review'))->toBeFalse()
        ->and($access->canPerform($admin, 'release'))->toBeFalse()
        ->and($access->canPerformForSubmission($admin, 'penro_receipt', $report))->toBeFalse()
        ->and($access->canRecordInternalRouting($admin, $report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO))->toBeFalse();

    $this->actingAs($admin)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), [
        'stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-05',
    ])->assertForbidden();

    expect($report->fresh()->date_received_penro)->toBeNull()
        ->and($report->fresh()->routingEvents()->count())->toBe(0);
});

test('Super Admin monitors the real PAMB owner without becoming a duplicate routing owner', function (): void {
    $admin = superAdminRoutingUser();
    $area = superAdminRoutingArea($admin, 'Super Admin PAMB TSD PA');
    $records = superAdminOperationalActor(OrganizationalAccessService::PENRO_RECORDS);
    $office = superAdminOperationalActor(OrganizationalAccessService::OFFICE_PENRO);
    $tsd = superAdminOperationalActor(OrganizationalAccessService::PENRO_TSD_CHIEF);
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'Global PAMB TSD monitoring',
        'target_office' => 'CENRO Mati', 'protected_area_id' => $area->id,
        'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'date_report_released_cenro' => '2026-08-04', 'date_received_penro' => '2026-08-05',
        'created_by' => $admin->id, 'updated_by' => $admin->id,
    ]);
    $timeline = app(PambRoutingTimelineService::class);
    $timeline->record($report, PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, '2026-08-06 09:00:00', $records->id);

    $this->actingAs($admin);
    $tracking = app(SubmissionTrackingService::class);
    $officeOwnedRow = $tracking->records()->firstWhere('source_id', $report->id);
    expect($officeOwnedRow['routing']['responsible_user_category'])->toBe(OrganizationalAccessService::OFFICE_PENRO)
        ->and($officeOwnedRow['routing']['current_location'])->toBe('For Receipt by Office of the PENRO');

    $timeline->record($report->fresh(), PambRoutingTimelineService::RECEIVED_BY_PENRO, '2026-08-06 10:00:00', $office->id);
    $timeline->record($report->fresh(), PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, '2026-08-06 11:00:00', $office->id);

    $this->actingAs($admin);
    $adminRow = $tracking->records()->firstWhere('source_id', $report->id);
    $adminCurrentStage = collect($adminRow['routing_timeline'])->firstWhere('status', 'current');
    $adminWorkspace = $tracking->workspaceQueues();

    expect($adminRow['routing']['responsible_user_category'])->toBe(OrganizationalAccessService::PENRO_TSD_CHIEF)
        ->and($adminRow['routing']['current_location'])->toBe('For Receipt by PENRO TSD Chief')
        ->and($adminRow['routing']['next_expected_action'])->toBe('Record Receipt by PENRO TSD Chief')
        ->and($adminCurrentStage['can_record'])->toBeFalse()
        ->and($adminRow['pamb_action_flags']['can_return_for_penro_correction'])->toBeFalse()
        ->and($adminRow['pamb_action_flags']['can_approve_for_regional_release'])->toBeFalse()
        ->and($adminWorkspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and(app(OrganizationalAccessService::class)->operationalCategories())->not->toContain(app(OrganizationalAccessService::class)->effectiveCategory($admin));

    $this->actingAs($tsd);
    $operationalWorkspace = app(SubmissionTrackingService::class)->workspaceQueues();
    expect($operationalWorkspace['incoming']->pluck('source_id')->all())->toContain($report->id)
        ->and($operationalWorkspace['outgoing']->pluck('source_id')->all())->not->toContain($report->id);
});
