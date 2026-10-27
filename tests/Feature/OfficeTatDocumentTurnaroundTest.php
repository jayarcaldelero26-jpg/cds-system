<?php

use App\Models\ConservationReportSubmission;
use App\Models\NonWorkingDay;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\OfficeTatDocumentTurnaroundService;
use App\Services\Pamo\PamoNativeSourceRegistry;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function officeDocumentTatGlobalActor(): User
{
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));

    return $actor;
}

function officeDocumentTatDetail(string $source, int $id, string $deadline, ?string $received = null, ?string $calendarOffice = null): array
{
    return [
        'label' => 'Processed report',
        'deadline' => $deadline,
        'received' => $received,
        'source_identity' => [
            'source_key' => $source,
            'source_id' => $id,
            'source_ids' => [$id],
            'calendar_office' => $calendarOffice,
            'workflow_key' => $source === 'engp' ? 'cbep' : 'regular_pamb',
            'workflow_label' => $source === 'engp' ? 'CBEP' : 'Regular PAMB',
            'submitted_activity_name' => 'Quarterly monitoring report',
            'document_type' => 'Progress report',
            'reporting_year' => 2026,
            'period_key' => 'quarter_4',
            'period_label' => 'Quarter 4',
        ],
    ];
}

function officeDocumentTatAssessment(string $unit, string $participantKey, string $participantName, array $details, string $asOf = '2026-10-27'): array
{
    return [
        'unit' => $unit,
        'period_key' => '2026-H2',
        'as_of_date' => $asOf,
        'office_tat_assessment' => [
            'as_of_date' => $asOf,
            'period_key' => '2026-H2',
            'rows' => [[
                'participant_key' => $participantKey,
                'name' => $participantName,
                'office_binding' => $unit === 'conservation' ? 'CENRO Baganga' : null,
                'obligation_details' => $details,
                'recorded_obligation_details' => [],
            ]],
        ],
    ];
}

function officeDocumentTatEvent(User $actor, string $source, int $sourceId, string $eventKey, string $fromStage, string $toStage, string $occurredAt, ?string $actionKey = null): void
{
    $metadata = $actionKey === null ? [] : ['action_key' => $actionKey];
    DB::table('document_routing_events')->insert([
        'source_type' => $source,
        'source_id' => $sourceId,
        'workflow_key' => $source === 'engp' ? 'cbep' : 'regular_pamb',
        'event_key' => $eventKey,
        'from_stage' => $fromStage,
        'to_stage' => $toStage,
        'from_office' => 'Origin office',
        'to_office' => 'Next office',
        'occurred_at' => $occurredAt,
        'recorded_by' => $actor->getKey(),
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('Conservation document turnaround keeps the first route start through corrections and counts only completed timestamp pairs', function (): void {
    $actor = officeDocumentTatGlobalActor();
    $genericDirect = 61001;
    $genericCorrection = 61002;
    $legacyOnly = 61003;
    $invalidStart = 61004;
    $futureReceipt = 61005;
    $nativeDirect = 61006;

    officeDocumentTatEvent($actor, 'conservation', $genericDirect, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-10-20 08:00:00', 'forward_from_pamo');
    officeDocumentTatEvent($actor, 'conservation', $genericDirect, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-26 08:00:00');
    officeDocumentTatEvent($actor, 'conservation', $genericDirect, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-27 08:00:00');
    officeDocumentTatEvent($actor, 'conservation', $genericDirect, 'released', DocumentRoutingProfileRegistry::PENRO_RECORDS, 'archived', '2026-10-28 09:00:00');
    officeDocumentTatEvent($actor, 'bms', $nativeDirect, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-10-21 09:00:00', 'forward_from_pamo');
    officeDocumentTatEvent($actor, 'bms', $nativeDirect, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-27 09:00:00');

    officeDocumentTatEvent($actor, 'conservation', $genericCorrection, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, '2026-10-24 09:00:00', 'forward_from_pamo_to_cenro_focal');
    officeDocumentTatEvent($actor, 'conservation', $genericCorrection, 'received', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, DocumentRoutingProfileRegistry::PREPARATION, '2026-10-24 12:00:00');
    officeDocumentTatEvent($actor, 'conservation', $genericCorrection, 'returned_for_correction', DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::PAMO_ORIGIN, '2026-10-25 12:00:00');
    officeDocumentTatEvent($actor, 'conservation', $genericCorrection, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, '2026-10-26 09:00:00', 'forward_from_pamo_to_cenro_focal');
    officeDocumentTatEvent($actor, 'conservation', $genericCorrection, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-27 09:00:00');

    officeDocumentTatEvent($actor, 'conservation', $invalidStart, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, 'not-a-timestamp', 'forward_from_pamo');
    officeDocumentTatEvent($actor, 'conservation', $invalidStart, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-27 11:00:00');

    officeDocumentTatEvent($actor, 'conservation', $futureReceipt, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-10-20 10:00:00', 'forward_from_pamo');
    officeDocumentTatEvent($actor, 'conservation', $futureReceipt, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-28 10:00:00');

    $area = ProtectedArea::query()->create([
        'name' => 'Document Turnaround PAMB Test PA', 'short_name' => 'DTT',
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'pamo' => 'DTT-PAMO',
    ]);
    $pamb = ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 4',
        'date_conducted' => '2026-10-23', 'date_accomplished' => '2026-10-23',
    ]);
    officeDocumentTatEvent($actor, 'conservation', (int) $pamb->getKey(), 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-10-23 08:00:00', 'forward_from_pamo');
    DB::table('pamb_routing_events')->insert([
        'conservation_report_submission_id' => $pamb->getKey(),
        'workflow_key' => 'regular_pamb',
        'stage_key' => PambRoutingTimelineService::RECORDS_RECEIVED,
        'occurred_at' => '2026-10-27 08:00:00',
        'recorded_by' => $actor->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $details = [
        officeDocumentTatDetail('conservation', $genericDirect, '2026-10-25'),
        officeDocumentTatDetail('bms', $nativeDirect, '2026-10-25'),
        officeDocumentTatDetail('conservation', $genericCorrection, '2026-10-25'),
        officeDocumentTatDetail('conservation', (int) $pamb->getKey(), '2026-10-25'),
        officeDocumentTatDetail('conservation', $legacyOnly, '2026-10-25', '2026-10-26'),
        officeDocumentTatDetail('conservation', $invalidStart, '2026-10-25'),
        officeDocumentTatDetail('conservation', $futureReceipt, '2026-10-25'),
    ];
    $assessment = officeDocumentTatAssessment('conservation', 'pa:1', 'Aliwagwag Protected Landscape', $details);
    $assessment['office_tat_assessment']['rows'][0]['recorded_obligation_details'] = [$details[0]];

    $result = app(OfficeTatDocumentTurnaroundService::class)->forAssessment($actor, $assessment);
    $row = $result['rows'][0];
    $reports = collect($row['documents'])->keyBy('key');

    expect($result['reports'])->toBe(7)
        ->and($row['reports'])->toBe(7)
        ->and($result['received_count'])->toBe(6)
        ->and($result['in_progress_count'])->toBe(1)
        ->and($result['overdue_count'])->toBe(1)
        ->and($result['average_supporting_documents'])->toBe(4)
        ->and($result['average_working_days'])->toBe(2.5)
        ->and($result['timing_unavailable_count'])->toBe(2)
        ->and($reports['conservation:61001']['elapsed_working_days'])->toBe(3)
        ->and($reports['bms:61006']['elapsed_working_days'])->toBe(3)
        ->and($reports['conservation:61002']['elapsed_working_days'])->toBe(2)
        ->and($reports['conservation:'.$pamb->getKey()]['received_at'])->toBe('2026-10-27T08:00:00+08:00')
        ->and($reports['conservation:61003']['timing_status'])->toBe('unavailable')
        ->and($reports['conservation:61004']['timing_status'])->toBe('unavailable')
        ->and($reports['conservation:61005']['status'])->toBe('Overdue')
        ->and($reports['conservation:61005']['elapsed_as_of'])->toStartWith('2026-10-27T23:59:59')
        ->and($reports['conservation:61005']['elapsed_working_days'])->toBe(4);
});

test('document turnaround uses the shared Monday-Thursday calendar, existing date boundaries, and record office scope', function (): void {
    BusinessCalendarService::forgetCache();
    $actor = officeDocumentTatGlobalActor();

    NonWorkingDay::create([
        'date' => '2026-08-25', 'name' => 'Baganga office day',
        'type' => NonWorkingDay::TYPE_OFFICE_DECLARED_NON_WORKING_DAY,
        'scope' => BusinessCalendarService::SCOPE_OFFICE, 'location' => 'CENRO Baganga', 'is_active' => true,
    ]);
    NonWorkingDay::create([
        'date' => '2026-08-26', 'name' => 'National weekday holiday',
        'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => BusinessCalendarService::SCOPE_NATIONAL, 'is_active' => true,
    ]);
    NonWorkingDay::create([
        'date' => '2026-08-28', 'name' => 'Friday holiday on excluded day',
        'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => BusinessCalendarService::SCOPE_NATIONAL, 'is_active' => true,
    ]);
    BusinessCalendarService::forgetCache();

    $times = [
        63001 => ['2026-08-24 08:00:00', '2026-08-27 13:00:00', 'CENRO Baganga'],
        63002 => ['2026-08-24 16:00:00', '2026-08-27 09:00:00', 'CENRO Mati'],
        63003 => ['2026-08-27 15:00:00', '2026-08-31 08:00:00', 'CENRO Baganga'],
        63004 => ['2026-08-27 08:15:00', '2026-08-27 17:30:00', 'CENRO Baganga'],
        63005 => ['2026-08-25 15:30:00', '2026-08-27 09:00:00', 'CENRO Mati'],
        63006 => ['2026-08-27 15:00:00', '2026-08-30 12:00:00', 'CENRO Baganga'],
    ];
    $details = [];
    foreach ($times as $id => [$start, $receipt, $office]) {
        officeDocumentTatEvent($actor, 'conservation', $id, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, $start, 'forward_from_pamo');
        officeDocumentTatEvent($actor, 'conservation', $id, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, $receipt);
        $details[] = officeDocumentTatDetail('conservation', $id, '2026-08-20', null, $office);
    }
    officeDocumentTatEvent($actor, 'conservation', 63007, 'forwarded', DocumentRoutingProfileRegistry::PAMO_ORIGIN, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, '2026-08-27 17:00:00', 'forward_from_pamo');
    $details[] = officeDocumentTatDetail('conservation', 63007, '2026-08-25', null, 'CENRO Baganga');

    $assessment = officeDocumentTatAssessment('conservation', 'pa:1', 'Aliwagwag Protected Landscape', $details, '2026-08-31');
    $result = app(OfficeTatDocumentTurnaroundService::class)->forAssessment($actor, $assessment);
    $reports = collect($result['rows'][0]['documents'])->keyBy('key');

    expect($reports['conservation:63001']['elapsed_working_days'])->toBe(1)
        ->and($reports['conservation:63002']['elapsed_working_days'])->toBe(2)
        ->and($reports['conservation:63003']['elapsed_working_days'])->toBe(1)
        ->and($reports['conservation:63004']['elapsed_working_days'])->toBe(0)
        ->and($reports['conservation:63005']['elapsed_working_days'])->toBe(1)
        ->and($reports['conservation:63006']['elapsed_working_days'])->toBe(0)
        ->and($reports['conservation:63006']['received_at'])->toBe('2026-08-30T12:00:00+08:00')
        ->and($reports['conservation:63007']['elapsed_working_days'])->toBe(1)
        ->and($reports['conservation:63007']['status'])->toBe('Overdue')
        ->and($result['average_supporting_documents'])->toBe(6)
        ->and($result['average_working_days'])->toBe(5 / 6);
});

test('Development starts at the CENRO Focal route and stays within the selected office unit', function (): void {
    $actor = User::factory()->create(['unit_assignment' => 'development', 'section' => 'ENGP', 'office_designated' => 'CENRO Baganga']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    officeDocumentTatEvent($actor, 'engp', 62001, 'forwarded', DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF, '2026-10-25 09:00:00', 'forward_to_cenro_chief');
    officeDocumentTatEvent($actor, 'engp', 62001, 'received', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, DocumentRoutingProfileRegistry::PENRO_RECORDS, '2026-10-27 09:00:00');

    $detail = officeDocumentTatDetail('engp', 62001, '2026-10-26', null, 'CENRO Baganga');
    $assessment = officeDocumentTatAssessment('development', 'cenro:CENRO Baganga', 'CENRO Baganga', [$detail]);
    $result = app(OfficeTatDocumentTurnaroundService::class)->forAssessment($actor, $assessment);

    expect($result['unit'])->toBe('development')
        ->and($result['rows'][0]['name'])->toBe('CENRO Baganga')
        ->and($result['average_supporting_documents'])->toBe(1)
        ->and($result['rows'][0]['average_working_days'])->toBe(2.0)
        ->and($result['rows'][0]['documents'][0]['elapsed_working_days'])->toBe(2);

    try {
        app(OfficeTatDocumentTurnaroundService::class)->forAssessment($actor, officeDocumentTatAssessment('conservation', 'pa:999', 'Foreign PA', []));
        $this->fail('A Development-only viewer must not receive Conservation turnaround results.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

test('turnaround uses the registered native PA source adapters without widening the group source list', function (): void {
    $service = app(OfficeTatDocumentTurnaroundService::class);
    $expected = ['conservation', ...array_values(array_map(static fn (array $source): string => $source['source'], app(PamoNativeSourceRegistry::class)->all()))];

    expect($service->sourceKeysFor('conservation'))->toBe($expected)
        ->and($service->sourceKeysFor('development'))->toBe(['engp']);
});
