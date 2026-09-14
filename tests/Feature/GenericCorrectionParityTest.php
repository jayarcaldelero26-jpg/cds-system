<?php

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Permission;

function correctionParityUser(string $section, string $office): User
{
    $user = User::factory()->create(['unit_assignment' => 'conservation', 'section' => $section, 'office_designated' => $office]);
    foreach (['reports.view', 'technical-reports.update'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    return $user;
}

function correctionParityReport(User $owner, string $name = 'Correction Homestay PA', ?string $shortName = 'CHPA'): ConservationReportSubmission
{
    $area = ProtectedArea::create([
        'name' => $name, 'short_name' => $shortName, 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Correction Homestay',
        'target_office' => 'CENRO Mati', 'protected_area_id' => $area->id,
        'date_accomplished' => '2026-08-03', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

function correctionParityTransition(SubmissionTrackingService $tracking, User $actor, int $record, string $action, ?string $remarks = null): void
{
    test()->actingAs($actor);
    $tracking->transition('conservation', $record, $action, null, $actor->id, $remarks);
}

function correctionParityRow(SubmissionTrackingService $tracking, int $record): array
{
    return $tracking->records()->first(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === $record);
}

test('generic Homestay supports CENRO, PENRO CDS Chief, and Office correction cycles', function (): void {
    $focal = correctionParityUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $cenroChief = correctionParityUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = correctionParityUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = correctionParityUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = correctionParityUser(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = correctionParityUser(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = correctionParityUser(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = correctionParityUser(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $report = correctionParityReport($focal);
    $tracking = app(SubmissionTrackingService::class);

    correctionParityTransition($tracking, $focal, $report->id, 'forward_to_cenro_chief');
    correctionParityTransition($tracking, $cenroChief, $report->id, 'receive_at_cenro_chief');
    $review = correctionParityRow($tracking, $report->id);
    $reviewActions = collect($review['routing']['actions']);
    expect($reviewActions->pluck('key')->all())->toContain('return_to_cenro_focal', 'forward_to_cenro_records')
        ->and($reviewActions->firstWhere('key', 'return_to_cenro_focal')['remarks_required'])->toBeTrue();

    correctionParityTransition($tracking, $cenroChief, $report->id, 'return_to_cenro_focal', 'Correct the technical narrative.');
    test()->actingAs($focal);
    $returned = correctionParityRow($tracking, $report->id);
    expect($returned['routing']['correction'])->toBeTrue()
        ->and($returned['routing']['current_status'])->toBe('Needs Correction')
        ->and($returned['routing']['current_location'])->toBe('CENRO CDS')
        ->and($returned['routing']['correction_reason'])->toBe('Correct the technical narrative.')
        ->and($tracking->queues()['needs_correction']->pluck('source_id')->all())->toContain($report->id);

    correctionParityTransition($tracking, $focal, $report->id, 'forward_to_cenro_chief');
    correctionParityTransition($tracking, $cenroChief, $report->id, 'receive_at_cenro_chief');
    correctionParityTransition($tracking, $cenroChief, $report->id, 'forward_to_cenro_records');
    correctionParityTransition($tracking, $cenroRecords, $report->id, 'receive_at_cenro_records');
    correctionParityTransition($tracking, $cenroRecords, $report->id, 'forward_to_penro_records');
    correctionParityTransition($tracking, $penroRecords, $report->id, 'receive_at_penro_records');
    correctionParityTransition($tracking, $penroRecords, $report->id, 'forward_to_office_penro');
    correctionParityTransition($tracking, $office, $report->id, 'receive_at_office_penro');
    correctionParityTransition($tracking, $office, $report->id, 'assign_to_tsd_chief');
    correctionParityTransition($tracking, $tsd, $report->id, 'receive_at_tsd_chief');
    correctionParityTransition($tracking, $tsd, $report->id, 'forward_to_cds_focal');
    correctionParityTransition($tracking, $penroFocal, $report->id, 'receive_at_cds_focal');
    correctionParityTransition($tracking, $penroFocal, $report->id, 'forward_to_cds_chief');
    correctionParityTransition($tracking, $penroChief, $report->id, 'receive_at_cds_chief');

    $penroReview = correctionParityRow($tracking, $report->id);
    $penroActions = collect($penroReview['routing']['actions']);
    expect($penroActions->pluck('key')->all())->toContain('return_to_penro_cds_focal', 'recommend_to_office_penro')
        ->and($penroActions->firstWhere('key', 'return_to_penro_cds_focal')['remarks_required'])->toBeTrue();

    correctionParityTransition($tracking, $penroChief, $report->id, 'return_to_penro_cds_focal', 'Revise the assessment.');
    test()->actingAs($penroFocal);
    $penroReturned = correctionParityRow($tracking, $report->id);
    expect($penroReturned['routing']['correction'])->toBeTrue()
        ->and($penroReturned['routing']['current_location'])->toBe('PENRO CDS')
        ->and($tracking->queues()['cds_correction']->pluck('source_id')->all())->toContain($report->id);

    correctionParityTransition($tracking, $penroFocal, $report->id, 'forward_to_cds_chief');
    correctionParityTransition($tracking, $penroChief, $report->id, 'receive_at_cds_chief');
    correctionParityTransition($tracking, $penroChief, $report->id, 'recommend_to_office_penro');
    correctionParityTransition($tracking, $office, $report->id, 'receive_at_office_penro_final');

    $officeReview = correctionParityRow($tracking, $report->id);
    $officeActions = collect($officeReview['routing']['actions']);
    expect($officeActions->pluck('key')->all())->toContain('return_from_office_for_correction', 'approve_for_regional_release')
        ->and($officeActions->firstWhere('key', 'return_from_office_for_correction')['remarks_required'])->toBeTrue();

    correctionParityTransition($tracking, $office, $report->id, 'return_from_office_for_correction', 'Add the final supporting reference.');
    test()->actingAs($penroFocal);
    $officeReturned = correctionParityRow($tracking, $report->id);
    expect($officeReturned['routing']['correction'])->toBeTrue()
        ->and($officeReturned['routing']['current_location'])->toBe('PENRO CDS')
        ->and($officeReturned['routing']['responsible_user_category'])->toBe('PENRO CDS Focal Person')
        ->and($tracking->queues()['cds_correction']->pluck('source_id')->all())->toContain($report->id);

    correctionParityTransition($tracking, $penroFocal, $report->id, 'forward_to_cds_chief');
    correctionParityTransition($tracking, $penroChief, $report->id, 'receive_at_cds_chief');
    correctionParityTransition($tracking, $penroChief, $report->id, 'recommend_to_office_penro');
    correctionParityTransition($tracking, $office, $report->id, 'receive_at_office_penro_final');
    correctionParityTransition($tracking, $office, $report->id, 'approve_for_regional_release');
    correctionParityTransition($tracking, $penroRecords, $report->id, 'receive_at_penro_records_final');
    correctionParityTransition($tracking, $penroRecords, $report->id, 'release_to_regional');

    $final = correctionParityRow($tracking, $report->id);
    expect($final['routing_complete'])->toBeTrue()
        ->and(collect($final['routing']['routing_history'])->where('event_type', 'returned_for_correction')->count())->toBe(3)
        ->and(collect($final['routing']['routing_history'])->pluck('remarks')->filter()->all())->toContain('Correct the technical narrative.', 'Revise the assessment.', 'Add the final supporting reference.');
});

test('generic correction actions are source-neutral across every tracked non-PAMB family', function (): void {
    $registry = app(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::class);

    foreach (['conservation', 'bms', 'bams', 'imea', 'aws', 'imea-maintenance', 'ipaf-management', 'revenue', 'management-plans'] as $source) {
        $actions = collect($registry->actionProfile($source)['actions'])->keyBy('key');
        expect($actions->get('return_to_cenro_focal')['correction'] ?? false)->toBeTrue()
            ->and($actions->get('return_to_penro_cds_focal')['correction'] ?? false)->toBeTrue()
            ->and($actions->get('return_from_office_for_correction')['correction'] ?? false)->toBeTrue();
    }
});

test('generic correction transitions reject wrong actors and missing remarks', function (): void {
    $focal = correctionParityUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = correctionParityUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $report = correctionParityReport($focal);
    $tracking = app(SubmissionTrackingService::class);

    correctionParityTransition($tracking, $focal, $report->id, 'forward_to_cenro_chief');
    correctionParityTransition($tracking, $chief, $report->id, 'receive_at_cenro_chief');

    expect(fn () => $tracking->transition('conservation', $report->id, 'return_to_cenro_focal', null, $focal->id, 'wrong actor'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(fn () => $tracking->transition('conservation', $report->id, 'return_to_cenro_focal', null, $chief->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
