<?php

use App\Models\BmsReportSubmission;
use App\Models\BamsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\Aws;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Models\SubmissionRoutingAttachment;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\RoutingAttachmentService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function phaseOneActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'section' => $category,
        'office_designated' => $office,
    ]);
    foreach (['reports.view', 'technical-reports.update', 'bms.update', 'bams.update', 'imea.update', 'aws.update', 'management-plans.update'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

function phaseOneGenericReport(User $owner): BmsReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Phase One PA', 'short_name' => 'P1PA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Phase One Report', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

/** @return \Illuminate\Database\Eloquent\Model */
function phaseOneReportForSource(string $source, User $owner): \Illuminate\Database\Eloquent\Model
{
    $area = ProtectedArea::create([
        'name' => $source === 'aws' ? 'Mt. Hamiguitan Range Wildlife Sanctuary' : "Phase One {$source} PA", 'short_name' => $source === 'aws' ? 'MHRWS' : 'P1'.strtoupper(substr($source, 0, 3)), 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);
    $common = [
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => "Phase One {$source} Report",
        'document_type' => 'Report', 'date_accomplished' => '2026-08-03', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ];

    return match ($source) {
        'bms' => BmsReportSubmission::create([...$common, 'semester' => '1st Semester']),
        'bams' => BamsReportSubmission::create([...$common, 'semester' => '1st Semester']),
        'imea' => ImeaReportSubmission::create([...$common, 'semester' => '1st Semester']),
        'aws' => Aws::create([...collect($common)->except('target_office')->all(), 'station_name' => 'Phase One AWS', 'location' => 'Mati', 'status' => 'Active']),
        'ipaf-management' => IpafManagementReport::create($common),
        'revenue' => IpafRevenueCollection::create([...collect($common)->except('date_accomplished')->all(), 'activity_name' => 'Revenue Collection', 'reporting_month' => 8, 'reporting_year' => 2026, 'total_collected' => '1000.00', 'deadline_submission' => '2026-08-10']),
        'imea-maintenance' => ImeaFacilityMaintenanceReport::create([...$common, 'quarter' => 'Quarter 1']),
        'management-plans' => ManagementPlan::create([...$common, 'plan_type' => 'Protected Area Management Plan', 'status' => 'Pending']),
        'conservation' => ConservationReportSubmission::create([...$common, 'workflow_key' => 'homestay', 'reporting_period' => 'Quarter 3']),
    };
}

test('generic PENRO Records receipt atomically hands off to Office of the PENRO', function (): void {
    $focal = phaseOneActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = phaseOneActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = phaseOneActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = phaseOneActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = phaseOneActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $report = phaseOneGenericReport($focal);
    $routing = app(DocumentRoutingTransitionService::class);

    foreach ([
        [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
        [$records, 'forward_to_penro_records'],
    ] as [$actor, $action]) $routing->transition($report, 'bms', $action, $actor->id);

    $receipt = $routing->transition($report->fresh(), 'bms', 'receive_at_penro_records', $penroRecords->id);
    $events = $routing->events($report->fresh(), 'bms');
    $state = $routing->state($report->fresh(), 'bms');

    $allowedOffice = $routing->presentation($report->fresh(), 'bms', null, $office)['allowed_actions'];
    $allowedPenro = $routing->presentation($report->fresh(), 'bms', null, $penroRecords)['allowed_actions'];

    expect($receipt->event_key)->toBe('received')
        ->and($events->pluck('metadata')->pluck('action_key')->all())->toContain('receive_at_penro_records', 'forward_to_office_penro')
        ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
        ->and(collect($allowedOffice)->pluck('key')->all())->toBe(['receive_at_office_penro'])
        ->and(collect($allowedPenro)->pluck('key')->all())->toBe([]);
});

test('generic correction reference uploads use correction_reference and never become effective copies', function (): void {
    Storage::fake('local');
    $focal = phaseOneActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = phaseOneActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $report = phaseOneGenericReport($focal);
    $routing = app(DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report->fresh(), 'bms', 'receive_at_cenro_chief', $chief->id);

    $file = UploadedFile::fake()->create('marked-up-reference.pdf', 12, 'application/pdf');
    test()->actingAs($chief)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->id, 'stage' => 'return_to_cenro_focal']), [
        'stage' => 'return_to_cenro_focal',
        'remarks' => 'Please correct the technical narrative.',
        'attachment' => $file,
    ])->assertSessionHasNoErrors();

    $attachment = SubmissionRoutingAttachment::query()->latest('id')->firstOrFail();
    expect($attachment->purpose)->toBe('correction_reference')
        ->and($attachment->document_routing_event_id)->not->toBeNull()
        ->and(app(RoutingAttachmentService::class)->currentDescriptor('bms', $report->id))->toBeNull();
});

test('generic Receive Correction rejects a forged working-copy upload', function (): void {
    Storage::fake('local');
    $focal = phaseOneActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = phaseOneActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $report = phaseOneGenericReport($focal);
    $routing = app(DocumentRoutingTransitionService::class);
    $routing->transition($report, 'bms', 'forward_to_cenro_chief', $focal->id);
    $routing->transition($report->fresh(), 'bms', 'receive_at_cenro_chief', $chief->id);
    $routing->transition($report->fresh(), 'bms', 'return_to_cenro_focal', $chief->id, 'Correct the technical narrative.');

    $response = $this->actingAs($focal)->post(route('submission-tracking.transition', [
        'source' => 'bms', 'record' => $report->id, 'stage' => 'receive_correction',
    ]), [
        'stage' => 'receive_correction',
        'attachment' => UploadedFile::fake()->create('incorrect-working-copy.pdf', 12, 'application/pdf'),
    ]);

    expect($routing->presentation($report->fresh(), 'bms', null, $focal)['allowed_actions'][0]['attachment_allowed'])->toBeFalse()
        ->and($routing->presentation($report->fresh(), 'bms', null, $focal)['allowed_actions'][0]['correction_reference_allowed'])->toBeFalse();
    $response->assertSessionHasErrors('attachment');
    expect(SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $report->id)->count())->toBe(0)
        ->and($routing->events($report->fresh(), 'bms')->last()->event_key)->toBe('returned_for_correction');
});

test('PAMB correction references remain historical and do not replace the working document', function (): void {
    Storage::fake('local');
    $actor = phaseOneActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Mati',
        'activity_name' => 'Phase One PAMB', 'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03', 'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    $event = $report->routingEvents()->create([
        'workflow_key' => $report->workflow_key,
        'stage_key' => PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION,
        'occurred_at' => '2026-08-05 09:00:00', 'recorded_by' => $actor->id,
    ]);
    $file = UploadedFile::fake()->create('pamb-reference.pdf', 12, 'application/pdf');
    $attachments = app(RoutingAttachmentService::class);
    $attachment = $attachments->create('conservation', $report->id, $file, $attachments->store($file), $actor, $event->stage_key, $event->stage_key, 'Reference copy', null, $event, 'correction_reference');

    expect($attachment->purpose)->toBe('correction_reference')
        ->and($attachment->pamb_routing_event_id)->toBe($event->id)
        ->and($attachments->currentDescriptor('conservation', $report->id))->toBeNull();
});

test('the canonical completion guard covers every non-NGP source model', function (): void {
    $tracking = app(SubmissionTrackingService::class);
    $models = [
        \App\Models\BmsReportSubmission::class,
        \App\Models\BamsReportSubmission::class,
        \App\Models\ImeaReportSubmission::class,
        \App\Models\ImeaFacilityMaintenanceReport::class,
        \App\Models\Aws::class,
        \App\Models\IpafManagementReport::class,
        \App\Models\IpafRevenueCollection::class,
        \App\Models\ManagementPlan::class,
    ];

    foreach ($models as $class) {
        $record = new $class;
        $record->setRawAttributes([
            'date_accomplished' => '2026-08-03',
            'date_report_released_cenro' => '2026-08-04',
            'date_received_penro' => '2026-08-04',
            'date_endorsed_regional' => '2026-08-05',
        ], true);

        expect(app(\App\Services\SubmissionTracking\RoutingStatusPresenter::class)->stage($record))->toBe('endorsed', $class)
            ->and($tracking->isRoutingComplete($record))->toBeTrue($class);
        expect(fn () => $tracking->assertMutable($record))->toThrow(ValidationException::class);
    }
});

test('all non-NGP generic profiles retain explicit final release while ordinary PENRO receipt is handled atomically', function (): void {
    $registry = app(DocumentRoutingProfileRegistry::class);
    foreach (['conservation', 'bms', 'bams', 'imea', 'aws', 'ipaf-management', 'imea-maintenance', 'revenue', 'management-plans'] as $source) {
        $actions = collect($registry->actionProfile($source)['actions'])->keyBy('key');
        expect($actions->get('receive_at_penro_records')['event_key'] ?? null)->toBe('received')
            ->and($actions->get('release_to_regional')['to'] ?? null)->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL);
    }
});

test('each generic source atomically transfers ordinary PENRO receipt ownership to Office PENRO', function (string $source): void {
    $focal = phaseOneActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = phaseOneActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = phaseOneActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = phaseOneActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = phaseOneActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $report = phaseOneReportForSource($source, $focal);
    $routing = app(DocumentRoutingTransitionService::class);

    $steps = $source === 'aws'
        ? []
        : [
            [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
            [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
            [$records, 'forward_to_penro_records'],
        ];
    foreach ($steps as [$actor, $action]) {
        $routing->transition($report->fresh(), $source, $action, $actor->id);
    }

    $before = $routing->presentation($report->fresh(), $source, null, $penroRecords);
    expect($before['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and(collect($before['allowed_actions'])->pluck('key')->all())->toContain('receive_at_penro_records');

    $receipt = $routing->transition($report->fresh(), $source, 'receive_at_penro_records', $penroRecords->id);
    $events = $routing->events($report->fresh(), $source);
    $penro = $routing->presentation($report->fresh(), $source, null, $penroRecords);
    $officePresentation = $routing->presentation($report->fresh(), $source, null, $office);
    $operationalActions = [
        $routing->presentation($report->fresh(), $source, null, $focal)['allowed_actions'],
        $routing->presentation($report->fresh(), $source, null, $chief)['allowed_actions'],
        $routing->presentation($report->fresh(), $source, null, $records)['allowed_actions'],
        $penro['allowed_actions'],
        $officePresentation['allowed_actions'],
    ];

    expect($receipt->event_key)->toBe('received')
        ->and($events->pluck('metadata')->pluck('action_key')->all())->toContain('receive_at_penro_records', 'forward_to_office_penro')
        ->and($routing->state($report->fresh(), $source)['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
        ->and(collect($penro['allowed_actions'])->pluck('key')->all())->toBe([])
        ->and(collect($officePresentation['allowed_actions'])->pluck('key')->all())->toBe(['receive_at_office_penro'])
        ->and(collect($operationalActions)->flatten(1)->count())->toBe(1)
        ->and(app(SubmissionTrackingService::class)->isRoutingComplete($report->fresh()))->toBeFalse()
        ->and(collect($routing->actionKeys($report->fresh(), $source))->contains('forward_to_office_penro'))->toBeFalse();
})->with(['bms', 'bams', 'imea', 'aws', 'ipaf-management', 'revenue', 'imea-maintenance', 'management-plans', 'conservation']);

test('generic final PENRO Records release is visible in Incoming before release for every active extended source', function (): void {
    $focal = phaseOneActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = phaseOneActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $records = phaseOneActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = phaseOneActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = phaseOneActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = phaseOneActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = phaseOneActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = phaseOneActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $routing = app(DocumentRoutingTransitionService::class);
    $tracking = app(SubmissionTrackingService::class);

    foreach (['ipaf-management', 'revenue', 'imea', 'imea-maintenance', 'management-plans'] as $source) {
        $report = phaseOneReportForSource($source, $focal);
        foreach ([
            [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
            [$chief, 'forward_to_cenro_records'], [$records, 'receive_at_cenro_records'],
            [$records, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
            [$office, 'receive_at_office_penro'], [$office, 'assign_to_tsd_chief'],
            [$tsd, 'receive_at_tsd_chief'], [$tsd, 'forward_to_cds_focal'],
            [$penroFocal, 'receive_at_cds_focal'], [$penroFocal, 'forward_to_cds_chief'],
            [$penroChief, 'receive_at_cds_chief'], [$penroChief, 'recommend_to_office_penro'],
            [$office, 'receive_at_office_penro_final'], [$office, 'approve_for_regional_release'],
            [$penroRecords, 'receive_at_penro_records_final'],
        ] as [$actor, $action]) {
            $routing->transition($report->fresh(), $source, $action, $actor->id);
        }

        test()->actingAs($penroRecords);
        $row = $tracking->workspaceQueues()['incoming']->firstWhere(
            fn (array $candidate): bool => $candidate['source'] === $source
                && (int) $candidate['source_id'] === $report->id
        );

        expect($row)->not->toBeNull()
            ->and($row['routing']['actions'])->not->toBeEmpty()
            ->and($row['incoming_action_category'])->toBe('release');

        $routing->transition($report->fresh(), $source, 'release_to_regional', $penroRecords->id);
        $workspace = $tracking->workspaceQueues();
        expect($workspace['incoming']->firstWhere(
            fn (array $candidate): bool => $candidate['source'] === $source
                && (int) $candidate['source_id'] === $report->id
        ))->toBeNull();
    }
});
