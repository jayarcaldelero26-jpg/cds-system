<?php

use App\Models\AuditLog;
use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\DocumentArchive;
use App\Models\EngpReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\RoutingPositionSettingVersion;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\EffectiveRoutingGraphResolver;
use App\Services\SubmissionTracking\RoutingPositionSettingsService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Database\Seeders\ModuleDefinitionSeeder;

function positionControlActor(string $category, string $office): User
{
    $user = User::factory()->create([
        'section' => $category,
        'office_designated' => $office,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('submission-tracking.view', 'web'));
    return $user;
}

function positionControlReport(string $name): BmsReportSubmission
{
    $creator = User::query()->firstOrFail();
    $area = ProtectedArea::create([
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 4)),
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
    ]);
    return BmsReportSubmission::query()->create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Position control routing fixture',
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);
}

function positionControlTransition(DocumentRoutingTransitionService $routing, BmsReportSubmission $record, string $action, User $actor, ?string $remarks = null, ?string $reason = null): DocumentRoutingEvent
{
    $payload = ['stage' => $action];
    if ($remarks !== null) $payload['remarks'] = $remarks;
    if ($reason !== null) $payload['correction_reason_key'] = $reason;
    test()->actingAs($actor)
        ->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => $action]), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    return DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->latest('id')->firstOrFail();
}

/** @return array{record:Model,path:string} */
function routingPositionRegressionSourceRecord(string $source, User $owner, ProtectedArea $area): array
{
    $dates = ['date_accomplished' => '2026-08-03', 'created_by' => $owner->id, 'updated_by' => $owner->id];
    $definition = match ($source) {
        'conservation' => [ConservationReportSubmission::class, [...$dates, 'workflow_key' => 'homestay', 'target_office' => 'CENRO Mati', 'activity_name' => 'Position source matrix', 'document_type' => 'Report']],
        'engp' => [EngpReportSubmission::class, ['workflow_key' => 'ngp_produce', 'office' => 'CENRO Mati', 'section_name' => 'NGP', 'activity_name' => 'Position ENGP source matrix', 'document_type' => 'Quarterly Report', 'reporting_year' => 2026, 'period_key' => 'q3', 'period_label' => 'Q3 2026', 'deadline_submission' => '2026-09-30', ...$dates]],
        'bms' => [BmsReportSubmission::class, [...$dates, 'semester' => '1st Semester 2026']],
        'bams' => [BamsReportSubmission::class, [...$dates, 'semester' => '1st Semester 2026']],
        'imea' => [ImeaReportSubmission::class, [...$dates, 'semester' => '1st Semester 2026']],
        'imea-maintenance' => [ImeaFacilityMaintenanceReport::class, [...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Position maintenance source matrix', 'document_type' => 'Report', 'quarter' => 'Q3 2026']],
        'aws' => [Aws::class, [...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'station_name' => 'Position source station', 'location' => 'Mati']],
        'ipaf-management' => [IpafManagementReport::class, [...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Position IPAF management source matrix', 'document_type' => 'Report']],
        'revenue' => [IpafRevenueCollection::class, ['date_report_released_cenro' => null, 'date_received_penro' => null, 'date_endorsed_regional' => null, 'created_by' => $owner->id, 'updated_by' => $owner->id, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'document_type' => 'Collection report', 'reporting_month' => 8, 'reporting_year' => 2026, 'total_collected' => '1200.00']],
        'management-plans' => [ManagementPlan::class, [...$dates, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'plan_type' => 'Protected Area Management Plan', 'title' => 'Position source matrix plan', 'version' => '1', 'prepared_year' => 2026, 'status' => 'Submitted']],
        default => throw new RuntimeException('Unrecognized routing test source '.$source),
    };
    [$model, $attributes] = $definition;
    if ($source !== 'engp') {
        $attributes['protected_area_id'] = $area->id;
        if ($source !== 'revenue') $attributes['target_office'] = 'CENRO Mati';
    }
    $record = $model::query()->create($attributes);
    $official = app(ProtectedAttachmentService::class)->officialDefinitionForRoutingSource($source);
    if (! $official) throw new RuntimeException('Missing official document mapping for '.$source);
    $path = $official['definition']['folder'].'/routing-position-'.$source.'-'.$record->getKey().'.pdf';
    if (($official['definition']['kind'] ?? null) === 'scalar') {
        $record->forceFill([$official['definition']['path'] => $path])->save();
        if (isset($official['definition']['name'])) $record->forceFill([$official['definition']['name'] => 'Source matrix official document.pdf'])->save();
    } else {
        $record->forceFill([$official['definition']['field'] => [['path' => $path, 'name' => 'Source matrix official document.pdf']]])->save();
    }
    Storage::disk('local')->put($path, "%PDF-1.4\n{$source} routing position fixture");
    return ['record' => $record->fresh(), 'path' => $path];
}

function routingPositionRegressionActor(string $category, string $office, ?int $protectedAreaId = null): User
{
    $actor = User::factory()->create([
        'section' => $category,
        'office_designated' => $office,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'protected_area_id' => $protectedAreaId,
        'is_active' => true,
        'is_approved' => true,
    ]);
    foreach (['reports.view', 'submission-tracking.view', 'technical-reports.update', 'bms.update', 'bams.update', 'imea.update', 'aws.update', 'management-plans.update'] as $ability) {
        $actor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    return $actor;
}

function routingPositionRegressionSet(User $admin, bool $office, bool $tsd): array
{
    $service = app(RoutingPositionSettingsService::class);
    $current = $service->current();
    return $current['office_penro_enabled'] === $office && $current['penro_tsd_chief_enabled'] === $tsd
        ? $current
        : $service->save($current['version'], $office, $tsd, 'Isolated routing regression matrix', $admin);
}

function routingPositionRegressionTransition(string $source, Model $record, string $action, User $actor, array $payload = []): DocumentRoutingEvent
{
    $response = test()->actingAs($actor)->post(
        route('submission-tracking.transition', ['source' => $source, 'record' => $record->getKey(), 'stage' => $action]),
        ['stage' => $action, ...$payload],
    );
    $response->assertRedirect()->assertSessionHasNoErrors();
    return DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $record->getKey())->latest('id')->firstOrFail();
}

function routingPositionRegressionPambReport(string $workflow, User $owner, ProtectedArea $area, string $suffix): ConservationReportSubmission
{
    $path = "conservation-report-movs/position-{$workflow}-{$suffix}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.4\n{$workflow} routing position report {$suffix}");
    return ConservationReportSubmission::query()->create([
        'workflow_key' => $workflow,
        'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Mati',
        'activity_name' => match ($workflow) {
            'regular_pamb' => 'Regular PAMB',
            'special_pamb' => 'Special PAMB',
            default => 'TWC Meeting',
        },
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3 2026',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'mov_file_path' => $path,
        'mov_file_name' => basename($path),
        'created_by' => $owner->getKey(),
        'updated_by' => $owner->getKey(),
    ]);
}

function routingPositionRegressionNotices(string $source, int $recordId): Collection
{
    return DB::table('notifications')->get()->filter(function ($notification) use ($source, $recordId): bool {
        $payload = json_decode((string) $notification->data, true) ?: [];
        return ($payload['source_type'] ?? null) === $source && (int) ($payload['source_id'] ?? 0) === $recordId;
    })->values();
}

beforeEach(function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
});

test('the effective graph preserves the all-enabled contract and resolves all four independent branches', function (): void {
    $profiles = app(DocumentRoutingProfileRegistry::class);
    $resolver = app(EffectiveRoutingGraphResolver::class);
    $position = fn (bool $office, bool $tsd): array => [
        'graph_version' => 'position-graph-v1',
        'version' => 1,
        'setting_version_id' => 1,
        'office_penro_enabled' => $office,
        'penro_tsd_chief_enabled' => $tsd,
        'profile' => 'preview',
    ];
    $base = $profiles->actionProfile('bms')['actions'];
    $both = $resolver->resolve('bms', false, $position(true, true));
    expect($both['actions'])->toBe($base)
        ->and(array_column($both['actions'], 'key'))->toBe(array_column($base, 'key'));

    $officeOffTsdOn = collect($resolver->resolve('bms', false, $position(false, true))['actions'])->keyBy('key');
    expect($officeOffTsdOn->has('forward_to_office_penro'))->toBeFalse()
        ->and($officeOffTsdOn->has('receive_at_office_penro'))->toBeFalse()
        ->and($officeOffTsdOn->get('dispatch_penro_records_to_tsd')['to'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_TSD)
        ->and($officeOffTsdOn->get('receive_at_tsd_chief')['from_office'])->toBe('PENRO Records Unit');

    $officeOnTsdOff = collect($resolver->resolve('bms', false, $position(true, false))['actions'])->keyBy('key');
    expect($officeOnTsdOff->has('receive_at_tsd_chief'))->toBeFalse()
        ->and($officeOnTsdOff->has('assign_to_tsd_chief'))->toBeFalse()
        ->and($officeOnTsdOff->has('forward_to_cds_focal'))->toBeFalse()
        ->and($officeOnTsdOff->get('dispatch_office_to_cds_focal')['from'])->toBe(DocumentRoutingProfileRegistry::OFFICE_PENRO);

    $bothOff = collect($resolver->resolve('bms', false, $position(false, false))['actions'])->keyBy('key');
    expect($bothOff->get('dispatch_penro_records_to_cds_focal')['to'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL)
        ->and($bothOff->has('dispatch_office_to_cds_focal'))->toBeFalse()
        ->and($bothOff->has('recommend_to_office_penro'))->toBeFalse()
        ->and($bothOff->get('recommend_to_penro_records_final')['event_key'])->toBe('recommended')
        ->and($bothOff->get('receive_at_penro_records_final')['from_office'])->toBe('PENRO CDS Chief')
        ->and($bothOff->has('approve_for_regional_release'))->toBeFalse();

    $direct = $resolver->resolve('bms', true, $position(false, false));
    expect(collect($direct['actions'])->pluck('key'))->not->toContain('forward_to_cenro_chief');
});

test('new routes capture one immutable settings version and execute the actual Records, receipt, and final recommendation actions', function (): void {
    $focal = positionControlActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $cenroChief = positionControlActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = positionControlActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = positionControlActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = positionControlActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = positionControlActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = positionControlActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = positionControlActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $settingsAdmin = User::factory()->create(['section' => 'CDS', 'office_designated' => 'PENRO Davao Oriental']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $settings = app(RoutingPositionSettingsService::class);
    $routing = app(DocumentRoutingTransitionService::class);

    $cases = [
        ['office' => true, 'tsd' => true, 'dispatch' => 'forward_to_office_penro', 'next' => OrganizationalAccessService::OFFICE_PENRO, 'next_action' => 'Forward to Office of the PENRO'],
        ['office' => false, 'tsd' => true, 'dispatch' => 'dispatch_penro_records_to_tsd', 'next' => OrganizationalAccessService::PENRO_TSD_CHIEF, 'next_action' => 'Dispatch to TSD Chief'],
        ['office' => true, 'tsd' => false, 'dispatch' => 'forward_to_office_penro', 'next' => OrganizationalAccessService::OFFICE_PENRO, 'next_action' => 'Forward to Office of the PENRO'],
        ['office' => false, 'tsd' => false, 'dispatch' => 'dispatch_penro_records_to_cds_focal', 'next' => OrganizationalAccessService::PENRO_FOCAL, 'next_action' => 'Dispatch to CDS Focal'],
    ];
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $archiveFake = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $archiveFake);

    foreach ($cases as $index => $case) {
        $current = $settings->current();
        if ($current['office_penro_enabled'] !== $case['office'] || $current['penro_tsd_chief_enabled'] !== $case['tsd']) {
            $current = $settings->save($current['version'], $case['office'], $case['tsd'], 'Matrix fixture '.$index, $settingsAdmin);
        }
        $record = positionControlReport('Position Matrix '.$index);
        $officialPath = 'bms-report-movs/position-controls-'.$record->id.'.pdf';
        $record->update(['mov_file_path' => $officialPath, 'mov_file_name' => 'Official report.pdf']);
        Storage::disk('local')->put($officialPath, "%PDF-1.4\nRouting position fixture {$index}");

        // Preview reads the current head but creates no sidecar row or event.
        $preview = $routing->state($record, 'bms');
        expect($preview['route_position']['preview'])->toBeTrue()
            ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->exists())->toBeFalse();

        positionControlTransition($routing, $record, 'forward_to_cenro_chief', $focal);
        $captured = SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->firstOrFail();
        $capturedVersion = RoutingPositionSettingVersion::query()->findOrFail($captured->setting_version_id);
        positionControlTransition($routing, $record, 'receive_at_cenro_chief', $cenroChief);
        positionControlTransition($routing, $record, 'forward_to_cenro_records', $cenroChief);
        positionControlTransition($routing, $record, 'receive_at_cenro_records', $cenroRecords);
        positionControlTransition($routing, $record, 'forward_to_penro_records', $cenroRecords);
        for ($cycle = 1; $cycle <= 2; $cycle++) {
            positionControlTransition($routing, $record, 'return_for_correction_penro_records', $penroRecords, 'Please correct cycle '.$cycle.'.', 'incomplete_document');
            $correctionState = $routing->state($record->fresh(), 'bms');
            expect(collect($correctionState['actions'])->pluck('key')->all())->toBe(['receive_correction']);
            positionControlTransition($routing, $record, 'receive_correction', $cenroRecords);
            $resubmission = positionControlTransition($routing, $record, 'forward_to_penro_records', $cenroRecords);
            expect(data_get($resubmission->metadata, 'correction_cycle'))->toBeTrue()
                ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->value('id'))->toBe($captured->id);
            // A second rejection of the resubmitted in-transit copy verifies
            // that another correction cycle keeps the same route snapshot.
        }
        positionControlTransition($routing, $record, 'receive_at_penro_records', $penroRecords);

        expect($capturedVersion->version)->toBe($current['version'])
            ->and((bool) $capturedVersion->office_penro_enabled)->toBe($case['office'])
            ->and((bool) $capturedVersion->penro_tsd_chief_enabled)->toBe($case['tsd']);

        $beforeDispatch = $routing->state($record->fresh(), 'bms');
        $beforeKeys = collect($beforeDispatch['actions'])->pluck('key');
        $presented = app(DocumentRoutingPresenter::class)->present($record->fresh(), 'bms', null, $routing->events($record->fresh(), 'bms'));
        $skipped = collect($presented['timeline'])->where('status', 'skipped');
        expect($presented['processing_percentage'])->toBe(80)
            ->and($presented['next_expected_action'])->toBe($case['next_action'])
            ->and($skipped->every(fn (array $row): bool => $row['display_status_label'] === 'Skipped by Routing Workflow Settings'))->toBeTrue()
            ->and($skipped->every(fn (array $row): bool => empty($row['occurred_at']) && empty($row['recorded_by']) && $row['status'] === 'skipped'))->toBeTrue();
        if (! $case['office']) {
            expect($beforeKeys)->not->toContain('forward_to_office_penro');
            $beforeCount = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count();
            $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => 'forward_to_office_penro']), ['stage' => 'forward_to_office_penro'])
                ->assertRedirect()->assertSessionHasErrors('stage');
            expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count())->toBe($beforeCount);
        }
        expect($beforeKeys)->toContain($case['dispatch']);
        $dispatchEvent = positionControlTransition($routing, $record, $case['dispatch'], $penroRecords);
        $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->where('logical_slot', 'mov')->firstOrFail();
        $archiveContext = collect($archiveFake->archiveContexts())->last();
        expect(data_get($dispatchEvent->metadata, 'routing_checkpoint'))->toBe('penro_records_initial_dispatch')
            ->and(data_get($dispatchEvent->metadata, 'route_snapshot_id'))->toBe($captured->id)
            ->and($archive->archive_status)->toBe('ARCHIVED')
            ->and(data_get($archiveContext, 'context.folder_path'))->toBe(['Conservation Unit', 'CENRO Mati', 'BMS']);

        $recipient = match ($case['next']) {
            OrganizationalAccessService::OFFICE_PENRO => $office,
            OrganizationalAccessService::PENRO_TSD_CHIEF => $tsd,
            default => $penroFocal,
        };
        $queueKey = match ($case['next']) {
            OrganizationalAccessService::OFFICE_PENRO => 'office_initial_routing',
            OrganizationalAccessService::PENRO_TSD_CHIEF => 'tsd_routing',
            default => 'cds_processing',
        };
        $this->actingAs($recipient);
        $tracking = app(SubmissionTrackingService::class);
        $queues = $tracking->queues([], $tracking->records(['program' => 'conservation']));
        expect(collect($queues[$queueKey] ?? [])->contains(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === (int) $record->id))->toBeTrue();

        if ($case['office']) {
            positionControlTransition($routing, $record, 'receive_at_office_penro', $office);
            if ($case['tsd']) {
                positionControlTransition($routing, $record, 'assign_to_tsd_chief', $office);
            } else {
                $officeActions = collect($routing->state($record->fresh(), 'bms')['actions'])->pluck('key');
                expect($officeActions)->toContain('dispatch_office_to_cds_focal')->not->toContain('assign_to_tsd_chief');
                $eventCount = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count();
                $this->actingAs($office)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => 'assign_to_tsd_chief']), ['stage' => 'assign_to_tsd_chief'])
                    ->assertRedirect()->assertSessionHasErrors('stage');
                expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count())->toBe($eventCount);
                positionControlTransition($routing, $record, 'dispatch_office_to_cds_focal', $office);
            }
        }
        if ($case['tsd']) {
            positionControlTransition($routing, $record, 'receive_at_tsd_chief', $tsd);
            positionControlTransition($routing, $record, 'forward_to_cds_focal', $tsd);
        }
        positionControlTransition($routing, $record, 'receive_at_cds_focal', $penroFocal);
        positionControlTransition($routing, $record, 'forward_to_cds_chief', $penroFocal);
        positionControlTransition($routing, $record, 'receive_at_cds_chief', $penroChief);

        $chiefState = $routing->state($record->fresh(), 'bms');
        $expectedFinalAction = $case['office'] ? 'recommend_to_office_penro' : 'recommend_to_penro_records_final';
        expect(collect($chiefState['actions'])->pluck('key'))->toContain($expectedFinalAction);
        if (! $case['office']) {
            expect(collect($chiefState['actions'])->pluck('key'))->not->toContain('recommend_to_office_penro');
            $eventCount = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count();
            $this->actingAs($penroChief)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => 'recommend_to_office_penro']), ['stage' => 'recommend_to_office_penro'])
                ->assertRedirect()->assertSessionHasErrors('stage');
            expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count())->toBe($eventCount);
            $recommendation = positionControlTransition($routing, $record, 'recommend_to_penro_records_final', $penroChief);
            expect($recommendation->event_key)->toBe('recommended')
                ->and($recommendation->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL);
            positionControlTransition($routing, $record, 'receive_at_penro_records_final', $penroRecords);
            positionControlTransition($routing, $record, 'release_to_regional', $penroRecords);
            $terminal = $routing->state($record->fresh(), 'bms');
            $finalActions = collect($terminal['actions'])->filter(fn (array $action): bool => $action['from'] === $terminal['stage']);
            $this->actingAs($penroRecords);
            $terminalRecords = app(SubmissionTrackingService::class)->records(['program' => 'conservation']);
            $terminalQueues = app(SubmissionTrackingService::class)->queues([], $terminalRecords);
            expect($terminal['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
                ->and($finalActions)->toBeEmpty()
                ->and(collect($terminalQueues['history'] ?? [])->contains(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === (int) $record->id))->toBeTrue()
                ->and(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->whereIn('event_key', ['approved'])->exists())->toBeFalse();
        }

        // Later global saves cannot change this report's selected graph.
        $freshState = $routing->state($record->fresh(), 'bms');
        expect($freshState['route_position']['version'])->toBe($capturedVersion->version)
            ->and($freshState['route_position']['office_penro_enabled'])->toBe($case['office'])
            ->and($freshState['route_position']['penro_tsd_chief_enabled'])->toBe($case['tsd']);
    }
});

test('every active source captures a disabled-position snapshot through real predecessor and archive HTTP actions', function (): void {
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingPositionRegressionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $officeActor = routingPositionRegressionActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsdActor = routingPositionRegressionActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Position controls ten-source PA', 'short_name' => 'PC10', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $matrix = [
        'conservation' => [false, true],
        'engp' => [true, false],
        'bms' => [false, false],
        'bams' => [false, true],
        'imea' => [true, false],
        'imea-maintenance' => [false, false],
        'aws' => [false, true],
        'ipaf-management' => [true, false],
        'revenue' => [false, false],
        'management-plans' => [false, true],
    ];

    foreach ($matrix as $source => [$officeEnabled, $tsdEnabled]) {
        $settings = routingPositionRegressionSet($settingsAdmin, $officeEnabled, $tsdEnabled);
        $watermark = (int) DB::table('routing_position_cutover_watermarks')->where('source_key', $source)->value('max_id');
        $fixture = routingPositionRegressionSourceRecord($source, $owner, $area);
        $record = $fixture['record'];
        expect((int) $record->getKey())->toBeGreaterThan($watermark);

        foreach ([
            [$focal, 'forward_to_cenro_chief'],
            [$chief, 'receive_at_cenro_chief'],
            [$chief, 'forward_to_cenro_records'],
            [$cenroRecords, 'receive_at_cenro_records'],
            [$cenroRecords, 'forward_to_penro_records'],
            [$penroRecords, 'receive_at_penro_records'],
        ] as [$actor, $action]) {
            routingPositionRegressionTransition($source, $record, $action, $actor);
        }
        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', $source)->where('source_id', $record->getKey())->firstOrFail();
        $snapshotVersion = RoutingPositionSettingVersion::query()->findOrFail($snapshot->setting_version_id);
        expect($snapshotVersion->getKey())->toBe((int) DB::table('routing_position_settings')->where('id', 1)->value('setting_version_id'))
            ->and((bool) $snapshotVersion->office_penro_enabled)->toBe($officeEnabled)
            ->and((bool) $snapshotVersion->penro_tsd_chief_enabled)->toBe($tsdEnabled);

        $expectedDispatchLabel = $officeEnabled
            ? 'Forward to Office of the PENRO'
            : ($tsdEnabled ? 'Dispatch to TSD Chief' : 'Dispatch to CDS Focal');
        $beforeDispatch = app(DocumentRoutingPresenter::class)->present(
            $record->fresh(), $source, null, app(DocumentRoutingTransitionService::class)->events($record->fresh(), $source)
        );
        expect($beforeDispatch['next_expected_action'])->toBe($expectedDispatchLabel);

        $dispatch = $officeEnabled ? 'forward_to_office_penro'
            : ($tsdEnabled ? 'dispatch_penro_records_to_tsd' : 'dispatch_penro_records_to_cds_focal');
        $event = routingPositionRegressionTransition($source, $record, $dispatch, $penroRecords);
        $sourceDefinition = app(SubmissionTrackingService::class)->source($source);
        $moduleCode = ($sourceDefinition['archive_module_code'])($record);
        $module = ModuleDefinition::query()->where('code', $moduleCode)->firstOrFail();
        $officialDefinition = app(ProtectedAttachmentService::class)->officialDefinitionForRoutingSource($source);
        $archive = DocumentArchive::query()->where('source_type', $source)->where('source_id', $record->getKey())->where('logical_slot', $officialDefinition['definition']['official_key'])->firstOrFail();
        $archiveContext = collect($gateway->archiveContexts())->last();
        expect(data_get($event->metadata, 'route_snapshot_id'))->toBe($snapshot->getKey())
            ->and(data_get($archiveContext, 'identity.source_type'))->toBe($source)
            ->and((int) data_get($archiveContext, 'identity.source_id'))->toBe((int) $record->getKey())
            ->and(data_get($archiveContext, 'context.folder_path'))->toBe([$sourceDefinition['archive_unit'], 'CENRO Mati', $module->name])
            ->and($archive->archive_status)->toBe('ARCHIVED');

        $recipient = $officeEnabled ? $officeActor : ($tsdEnabled ? $tsdActor : $penroFocal);
        $queue = $officeEnabled ? 'office_initial_routing' : ($tsdEnabled ? 'tsd_routing' : 'cds_processing');
        $this->actingAs($recipient);
        $tracking = app(SubmissionTrackingService::class);
        $records = $tracking->records($source === 'engp' ? ['program' => 'engp'] : ['program' => 'conservation']);
        $queues = $tracking->queues([], $records);
        expect(collect($queues[$queue] ?? [])->contains(fn (array $row): bool => $row['source'] === $source && (int) $row['source_id'] === (int) $record->getKey()))->toBeTrue();
        $detail = $this->get(route('submission-tracking.index', ['source' => $source, 'source_id' => $record->getKey(), 'view' => 'incoming']))
            ->assertOk()->inertiaProps();
        expect(data_get($detail, 'trackingContext.selected_record.source'))->toBe($source)
            ->and((int) data_get($detail, 'trackingContext.selected_record.source_id'))->toBe((int) $record->getKey());

        $notices = DB::table('notifications')->get()->filter(function ($notice) use ($source, $record): bool {
            $payload = json_decode((string) $notice->data, true) ?: [];
            return ($payload['source_type'] ?? null) === $source && (int) ($payload['source_id'] ?? 0) === (int) $record->getKey();
        });
        expect($notices->contains(fn ($notice): bool => (int) $notice->notifiable_id === (int) $recipient->id))->toBeTrue();
        if (! $officeEnabled) expect($notices->contains(fn ($notice): bool => (int) $notice->notifiable_id === (int) $officeActor->id))->toBeFalse();
        if (! $tsdEnabled) expect($notices->contains(fn ($notice): bool => (int) $notice->notifiable_id === (int) $tsdActor->id))->toBeFalse();
    }
});

test('submission tracking bulk-resolves route snapshots with bounded settings reads', function (): void {
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $this->seed(ModuleDefinitionSeeder::class);
    for ($index = 0; $index < 5; $index++) positionControlReport('Position Query Budget '.$index);

    $counts = ['submission_routing_snapshots' => 0, 'routing_position_settings' => 0, 'routing_position_setting_versions' => 0, 'routing_position_cutover_watermarks' => 0];
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$counts): void {
        foreach (array_keys($counts) as $table) {
            if (preg_match('/\b(?:from|join|update|into)\s+[`"\[]?'.preg_quote($table, '/').'[`"\]]?/i', $query->sql)) $counts[$table]++;
        }
    });

    $this->actingAs($owner);
    $records = app(SubmissionTrackingService::class)->records(['program' => 'conservation']);
    expect($records->count())->toBeGreaterThanOrEqual(5);

    // CENRO source queries now include one correlated snapshot-profile scope;
    // the list projection then performs its existing bounded snapshot batch read.
    expect($counts['submission_routing_snapshots'])->toBeLessThanOrEqual(2)
        ->and($counts['routing_position_settings'])->toBeLessThanOrEqual(1)
        ->and($counts['routing_position_setting_versions'])->toBeLessThanOrEqual(2)
        ->and($counts['routing_position_cutover_watermarks'])->toBeLessThanOrEqual(1);
});

test('routing rechecks the current actor office before capturing a route snapshot', function (): void {
    $focal = positionControlActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $wrongCategory = positionControlActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $chief = positionControlActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $record = positionControlReport('Current actor office revalidation');

    $this->actingAs($wrongCategory)->post(route('submission-tracking.transition', ['bms', $record->id, 'forward_to_cenro_chief']), [
        'stage' => 'forward_to_cenro_chief',
    ])->assertForbidden();
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->exists())->toBeFalse()
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->exists())->toBeFalse();

    User::query()->whereKey($focal->id)->update(['office_designated' => 'CENRO Tagum']);
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['bms', $record->id, 'forward_to_cenro_chief']), [
        'stage' => 'forward_to_cenro_chief',
    ])->assertForbidden();
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->exists())->toBeFalse()
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->exists())->toBeFalse();

    $this->actingAs($chief)->post(route('submission-tracking.transition', ['bms', $record->id, 'receive_at_cenro_chief']), [
        'stage' => 'receive_at_cenro_chief',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->exists())->toBeFalse()
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->exists())->toBeFalse();

    User::query()->whereKey($focal->id)->update(['office_designated' => 'CENRO Mati']);
    $this->actingAs($focal)->post(route('submission-tracking.transition', ['bms', $record->id, 'forward_to_cenro_chief']), [
        'stage' => 'forward_to_cenro_chief',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count())->toBe(1)
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->exists())->toBeTrue();
});

test('PAMB MOV correction and setting re-enable preserve the captured graph while new reports use the latest version', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingPositionRegressionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $penroFocal = routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $disabled = routingPositionRegressionSet($settingsAdmin, false, false);
    $area = ProtectedArea::query()->create([
        'name' => 'PAMB correction immutable graph', 'short_name' => 'PCIG', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
    ]);
    $report = routingPositionRegressionPambReport('regular_pamb', $focal, $area, 'correction');

    $this->actingAs($focal)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_chief']), [
        'stage' => 'forward_to_cenro_chief',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->exists())->toBeFalse()
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->exists())->toBeFalse();

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_chief', $focal);
    routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_chief', $chief);
    $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->firstOrFail();
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Attach the signed attendance sheet.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(2)
        ->and(PambRoutingEvent::query()->where('conservation_report_submission_id', $report->id)->count())->toBe(0)
        ->and(app(DocumentRoutingTransitionService::class)->state($report->fresh()->load('protectedArea'), 'conservation')['stage'])->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF);

    routingPositionRegressionTransition('conservation', $report, 'return_to_cenro_focal', $chief, ['remarks' => 'Replace the corrected MOV.']);
    routingPositionRegressionTransition('conservation', $report, 'receive_correction', $focal);
    $oldMov = $report->fresh()->mov_file_path;
    $this->actingAs($focal)->put(route('conservation-reports.update', ['regular_pamb', $report->id]), [
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 3', 'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'mov' => UploadedFile::fake()->createWithContent('corrected-pamb.pdf', "%PDF-1.4\nCorrected PAMB MOV"),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $corrected = $report->fresh();
    expect($corrected->mov_file_path)->not->toBe($oldMov)
        ->and($corrected->mov_file_name)->toBe('corrected-pamb.pdf')
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->value('id'))->toBe($snapshot->id);
    Storage::disk('local')->assertExists($corrected->mov_file_path);
    Storage::disk('local')->assertMissing($oldMov);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_chief', $focal);
    routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_chief', $chief);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $reenabled = app(RoutingPositionSettingsService::class)->save($disabled['version'], true, true, 'Re-enabled while prior report is pending', $settingsAdmin);
    expect($reenabled['version'])->toBe($disabled['version'] + 1);
    routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_records', $chief);
    routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_records', $cenroRecords);
    routingPositionRegressionTransition('conservation', $report, 'forward_to_penro_records', $cenroRecords);
    routingPositionRegressionTransition('conservation', $report, 'receive_at_penro_records', $penroRecords);
    $routing = app(DocumentRoutingTransitionService::class);
    $pending = $routing->state($report->fresh()->load('protectedArea'), 'conservation');
    expect($pending['route_position']['setting_version_id'])->toBe($snapshot->setting_version_id)
        ->and($pending['route_position']['office_penro_enabled'])->toBeFalse()
        ->and($pending['route_position']['penro_tsd_chief_enabled'])->toBeFalse()
        ->and(collect($pending['actions'])->pluck('key'))->toContain('dispatch_penro_records_to_cds_focal')->not->toContain('forward_to_office_penro');

    $before = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_office_penro']), [
        'stage' => 'forward_to_office_penro',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($before);
    routingPositionRegressionTransition('conservation', $report, 'dispatch_penro_records_to_cds_focal', $penroRecords);
    expect(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->value('setting_version_id'))->toBe($snapshot->setting_version_id);

    $newReport = routingPositionRegressionPambReport('regular_pamb', $focal, $area, 'reenabled');
    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $newReport->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    routingPositionRegressionTransition('conservation', $newReport, 'forward_to_cenro_chief', $focal);
    $newSnapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $newReport->id)->firstOrFail();
    $newVersion = RoutingPositionSettingVersion::query()->findOrFail($newSnapshot->setting_version_id);
    expect($newVersion->version)->toBe($reenabled['version'])
        ->and($newVersion->office_penro_enabled)->toBeTrue()
        ->and($newVersion->penro_tsd_chief_enabled)->toBeTrue();
});

test('Regular PAMB Special PAMB and TWC execute all four saved graphs through real HTTP and terminal release', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $cenroChief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingPositionRegressionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = routingPositionRegressionActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = routingPositionRegressionActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = routingPositionRegressionActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $workflows = ['regular_pamb', 'special_pamb', 'twc_meetings'];
    $cases = [[true, true], [false, true], [true, false], [false, false]];
    $counter = 0;

    foreach ($workflows as $workflow) {
        foreach ($cases as [$officeEnabled, $tsdEnabled]) {
            $settings = routingPositionRegressionSet($settingsAdmin, $officeEnabled, $tsdEnabled);
            $area = ProtectedArea::query()->create([
                'name' => 'PAMB Position '.$workflow.' '.$counter, 'short_name' => 'PP'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
                'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
                'created_by' => $focal->id, 'updated_by' => $focal->id,
            ]);
            ProtectedAreaOfficeAssignment::query()->create([
                'protected_area_id' => $area->id,
                'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
                'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
            ]);
            $report = routingPositionRegressionPambReport($workflow, $focal, $area, (string) $counter);

            $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
                ->assertRedirect()->assertSessionHasNoErrors();
            routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_chief', $focal);
            routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_chief', $cenroChief);
            $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->firstOrFail();
            $snapshotVersion = RoutingPositionSettingVersion::query()->findOrFail($snapshot->setting_version_id);
            expect($snapshotVersion->version)->toBe($settings['version'])
                ->and($snapshot->profile)->toBe('regular')
                ->and($snapshotVersion->office_penro_enabled)->toBe($officeEnabled)
                ->and($snapshotVersion->penro_tsd_chief_enabled)->toBe($tsdEnabled);

            $eventCount = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
            $this->actingAs($cenroChief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_records']), ['stage' => 'forward_to_cenro_records'])
                ->assertRedirect()->assertSessionHasErrors('stage');
            expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($eventCount);
            $this->actingAs($cenroChief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
                'decision' => PambMovProcessingService::READY_FOR_RELEASE,
            ])->assertRedirect()->assertSessionHasNoErrors();
            routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_records', $cenroChief);
            routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_records', $cenroRecords);
            routingPositionRegressionTransition('conservation', $report, 'forward_to_penro_records', $cenroRecords);
            routingPositionRegressionTransition('conservation', $report, 'receive_at_penro_records', $penroRecords);

            $firstDispatch = $officeEnabled ? 'forward_to_office_penro'
                : ($tsdEnabled ? 'dispatch_penro_records_to_tsd' : 'dispatch_penro_records_to_cds_focal');
            $checkpoint = routingPositionRegressionTransition('conservation', $report, $firstDispatch, $penroRecords);
            expect(data_get($checkpoint->metadata, 'route_snapshot_id'))->toBe($snapshot->getKey())
                ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('logical_slot', 'mov')->value('archive_status'))->toBe('ARCHIVED')
                ->and(data_get(collect($gateway->archiveContexts())->last(), 'context.folder_path.1'))->toBe('CENRO Mati');

            $initialRecipient = $officeEnabled ? $office : ($tsdEnabled ? $tsd : $penroFocal);
            $initialQueue = $officeEnabled ? 'office_initial_routing' : ($tsdEnabled ? 'tsd_routing' : 'cds_processing');
            $this->actingAs($initialRecipient);
            $tracking = app(SubmissionTrackingService::class);
            $queues = $tracking->queues([], $tracking->records(['program' => 'conservation']));
            expect(collect($queues[$initialQueue] ?? [])->contains(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === (int) $report->id))->toBeTrue();
            $selected = $this->get(route('submission-tracking.index', ['source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming']))
                ->assertOk()->inertiaProps();
            expect(data_get($selected, 'trackingContext.selected_record.source'))->toBe('conservation')
                ->and((int) data_get($selected, 'trackingContext.selected_record.source_id'))->toBe((int) $report->id);

            if ($officeEnabled) {
                routingPositionRegressionTransition('conservation', $report, 'receive_at_office_penro', $office);
                if ($tsdEnabled) {
                    routingPositionRegressionTransition('conservation', $report, 'assign_to_tsd_chief', $office);
                } else {
                    $officeKeys = collect(app(DocumentRoutingTransitionService::class)->state($report->fresh(), 'conservation')['actions'])->pluck('key');
                    expect($officeKeys)->toContain('dispatch_office_to_cds_focal')->not->toContain('assign_to_tsd_chief');
                    $before = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
                    $this->actingAs($office)->post(route('submission-tracking.transition', ['conservation', $report->id, 'assign_to_tsd_chief']), ['stage' => 'assign_to_tsd_chief'])
                        ->assertRedirect()->assertSessionHasErrors('stage');
                    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($before);
                    routingPositionRegressionTransition('conservation', $report, 'dispatch_office_to_cds_focal', $office);
                }
            }
            if ($tsdEnabled) {
                routingPositionRegressionTransition('conservation', $report, 'receive_at_tsd_chief', $tsd);
                routingPositionRegressionTransition('conservation', $report, 'forward_to_cds_focal', $tsd);
            }
            routingPositionRegressionTransition('conservation', $report, 'receive_at_cds_focal', $penroFocal);
            routingPositionRegressionTransition('conservation', $report, 'forward_to_cds_chief', $penroFocal);
            routingPositionRegressionTransition('conservation', $report, 'receive_at_cds_chief', $penroChief);

            if ($officeEnabled) {
                routingPositionRegressionTransition('conservation', $report, 'recommend_to_office_penro', $penroChief);
                routingPositionRegressionTransition('conservation', $report, 'receive_at_office_penro_final', $office);
                routingPositionRegressionTransition('conservation', $report, 'approve_for_regional_release', $office);
                expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('event_key', 'approved')->exists())->toBeTrue();
            } else {
                expect(collect(app(DocumentRoutingTransitionService::class)->state($report->fresh(), 'conservation')['actions'])->pluck('key'))
                    ->not->toContain('recommend_to_office_penro')->not->toContain('approve_for_regional_release');
                $recommendation = routingPositionRegressionTransition('conservation', $report, 'recommend_to_penro_records_final', $penroChief);
                expect($recommendation->event_key)->toBe('recommended')
                    ->and($recommendation->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL)
                    ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('event_key', 'approved')->exists())->toBeFalse();
            }
            routingPositionRegressionTransition('conservation', $report, 'receive_at_penro_records_final', $penroRecords);
            routingPositionRegressionTransition('conservation', $report, 'release_to_regional', $penroRecords);
            $state = app(DocumentRoutingTransitionService::class)->state($report->fresh(), 'conservation');
            $history = app(SubmissionTrackingService::class)->queues([], app(SubmissionTrackingService::class)->records(['program' => 'conservation']))['history'];
            expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
                ->and($report->fresh()->date_endorsed_regional)->not->toBeNull()
                ->and(collect($state['actions'])->filter(fn (array $action): bool => $action['from'] === $state['stage']))->toBeEmpty()
                ->and(collect($history)->contains(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === (int) $report->id))->toBeTrue()
                ->and($report->fresh()->date_report_released_cenro)->not->toBeNull()
                ->and($report->fresh()->date_received_penro)->not->toBeNull();

            $notices = routingPositionRegressionNotices('conservation', (int) $report->id);
            if (! $officeEnabled) expect($notices->contains(fn ($notice): bool => (int) $notice->notifiable_id === (int) $office->id))->toBeFalse();
            if (! $tsdEnabled) expect($notices->contains(fn ($notice): bool => (int) $notice->notifiable_id === (int) $tsd->id))->toBeFalse();
            $counter++;
        }
    }
});

test('direct MHRWS Regular PAMB Special PAMB and TWC execute all four saved graphs through HTTP', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = routingPositionRegressionActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $tsd = routingPositionRegressionActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental');
    $penroFocal = routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $penroChief = routingPositionRegressionActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental');
    $area = ProtectedArea::query()->create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $penroRecords->id, 'updated_by' => $penroRecords->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $penroRecords->id,
    ]);
    $cases = [[true, true], [false, true], [true, false], [false, false]];
    $counter = 0;

    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        foreach ($cases as [$officeEnabled, $tsdEnabled]) {
            $settings = routingPositionRegressionSet($settingsAdmin, $officeEnabled, $tsdEnabled);
            $report = routingPositionRegressionPambReport($workflow, $penroRecords, $area, 'direct-'.$counter);
            $routing = app(DocumentRoutingTransitionService::class);
            $before = $routing->state($report->fresh()->load('protectedArea'), 'conservation');
            expect($before['stage'])->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
                ->and($before['route_position']['preview'])->toBeTrue()
                ->and($before['profile']['key'])->toBe('canonical_direct_penro')
                ->and($before['route_position']['profile'])->toBe('preview');

            routingPositionRegressionTransition('conservation', $report, 'receive_at_penro_records', $penroRecords);
            $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->firstOrFail();
            $version = RoutingPositionSettingVersion::query()->findOrFail($snapshot->setting_version_id);
            expect($snapshot->profile)->toBe('direct')
                ->and($version->version)->toBe($settings['version'])
                ->and((bool) $version->office_penro_enabled)->toBe($officeEnabled)
                ->and((bool) $version->penro_tsd_chief_enabled)->toBe($tsdEnabled);

            $expectedDispatchLabel = $officeEnabled
                ? 'Forward to Office of the PENRO'
                : ($tsdEnabled ? 'Dispatch to TSD Chief' : 'Dispatch to CDS Focal');
            $beforeDispatch = app(DocumentRoutingPresenter::class)->present(
                $report->fresh()->load('protectedArea'), 'conservation', null, $routing->events($report->fresh(), 'conservation')
            );
            expect($beforeDispatch['profile_key'])->toBe('canonical_direct_penro')
                ->and($beforeDispatch['next_expected_action'])->toBe($expectedDispatchLabel);

            $dispatch = $officeEnabled ? 'forward_to_office_penro'
                : ($tsdEnabled ? 'dispatch_penro_records_to_tsd' : 'dispatch_penro_records_to_cds_focal');
            $event = routingPositionRegressionTransition('conservation', $report, $dispatch, $penroRecords);
            expect(data_get($event->metadata, 'route_profile'))->toBe('direct')
                ->and(data_get($event->metadata, 'route_snapshot_id'))->toBe($snapshot->getKey())
                ->and(DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('logical_slot', 'mov')->value('archive_status'))->toBe('ARCHIVED')
                ->and(data_get(collect($gateway->archiveContexts())->last(), 'context.folder_path.1'))->toBe('CENRO Mati');

            if ($officeEnabled) {
                routingPositionRegressionTransition('conservation', $report, 'receive_at_office_penro', $office);
                if ($tsdEnabled) routingPositionRegressionTransition('conservation', $report, 'assign_to_tsd_chief', $office);
                else routingPositionRegressionTransition('conservation', $report, 'dispatch_office_to_cds_focal', $office);
            }
            if ($tsdEnabled) {
                routingPositionRegressionTransition('conservation', $report, 'receive_at_tsd_chief', $tsd);
                routingPositionRegressionTransition('conservation', $report, 'forward_to_cds_focal', $tsd);
            }
            routingPositionRegressionTransition('conservation', $report, 'receive_at_cds_focal', $penroFocal);
            routingPositionRegressionTransition('conservation', $report, 'forward_to_cds_chief', $penroFocal);
            routingPositionRegressionTransition('conservation', $report, 'receive_at_cds_chief', $penroChief);
            if ($officeEnabled) {
                routingPositionRegressionTransition('conservation', $report, 'recommend_to_office_penro', $penroChief);
                routingPositionRegressionTransition('conservation', $report, 'receive_at_office_penro_final', $office);
                routingPositionRegressionTransition('conservation', $report, 'approve_for_regional_release', $office);
            } else {
                routingPositionRegressionTransition('conservation', $report, 'recommend_to_penro_records_final', $penroChief);
                expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->where('event_key', 'approved')->exists())->toBeFalse();
            }
            routingPositionRegressionTransition('conservation', $report, 'receive_at_penro_records_final', $penroRecords);
            routingPositionRegressionTransition('conservation', $report, 'release_to_regional', $penroRecords);
            $releasedPresentation = app(DocumentRoutingPresenter::class)->present(
                $report->fresh()->load('protectedArea'), 'conservation', null, $routing->events($report->fresh(), 'conservation')
            );
            expect($routing->state($report->fresh()->load('protectedArea'), 'conservation')['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
                ->and($releasedPresentation['next_expected_action'])->toBe('No further routing action')
                ->and($report->fresh()->date_report_released_cenro)->toBeNull()
                ->and($report->fresh()->date_endorsed_regional)->not->toBeNull();
            $counter++;
        }
    }
});

test('archive rejection rolls back each initial dispatch destination without losing the prior snapshot or sending its handoff notice', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingPositionRegressionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $recipients = [
        OrganizationalAccessService::OFFICE_PENRO => routingPositionRegressionActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'),
        OrganizationalAccessService::PENRO_TSD_CHIEF => routingPositionRegressionActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
        OrganizationalAccessService::PENRO_FOCAL => routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'),
    ];
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $area = ProtectedArea::create([
        'name' => 'Position controls archive rejection PA', 'short_name' => 'PCAR', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);
    $destinations = [
        ['office' => true, 'tsd' => true, 'key' => 'forward_to_office_penro', 'recipient' => $recipients[OrganizationalAccessService::OFFICE_PENRO]],
        ['office' => false, 'tsd' => true, 'key' => 'dispatch_penro_records_to_tsd', 'recipient' => $recipients[OrganizationalAccessService::PENRO_TSD_CHIEF]],
        ['office' => false, 'tsd' => false, 'key' => 'dispatch_penro_records_to_cds_focal', 'recipient' => $recipients[OrganizationalAccessService::PENRO_FOCAL]],
    ];

    routingPositionRegressionSet($settingsAdmin, true, true);
    $firstCheckpoint = positionControlReport('Archive reject first captured snapshot');
    $firstCheckpointPath = 'bms-report-movs/archive-first-snapshot-'.$firstCheckpoint->id.'.pdf';
    $firstCheckpoint->update([
        'date_received_penro' => '2026-08-04',
        'mov_file_path' => $firstCheckpointPath,
        'mov_file_name' => 'First checkpoint official report.pdf',
    ]);
    Storage::disk('local')->put($firstCheckpointPath, "%PDF-1.4\nfirst snapshot archive rollback fixture");
    $firstNoticeCount = routingPositionRegressionNotices('bms', (int) $firstCheckpoint->id)->count();
    $gateway->rejectNextUpload();
    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['bms', $firstCheckpoint->id, 'forward_to_office_penro']), [
        'stage' => 'forward_to_office_penro',
    ])->assertRedirect()->assertSessionHasErrors('archive');
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $firstCheckpoint->id)->count())->toBe(0)
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $firstCheckpoint->id)->count())->toBe(0)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $firstCheckpoint->id)->exists())->toBeFalse()
        ->and($firstCheckpoint->fresh()->date_received_penro->toDateString())->toBe('2026-08-04')
        ->and($firstCheckpoint->fresh()->mov_file_path)->toBe($firstCheckpointPath)
        ->and(routingPositionRegressionNotices('bms', (int) $firstCheckpoint->id)->count())->toBe($firstNoticeCount)
        ->and(app(DocumentRoutingTransitionService::class)->state($firstCheckpoint->fresh(), 'bms')['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
        ->and($gateway->uploadAttempts())->toBe(1);

    foreach ($destinations as $index => $destination) {
        routingPositionRegressionSet($settingsAdmin, $destination['office'], $destination['tsd']);
        $record = positionControlReport('Archive reject destination '.$index);
        $record->update(['protected_area_id' => $area->id, 'target_office' => 'CENRO Mati']);
        $path = 'bms-report-movs/archive-reject-'.$index.'-'.$record->id.'.pdf';
        $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Existing official report.pdf']);
        Storage::disk('local')->put($path, "%PDF-1.4\nexisting official source document {$index}");

        foreach ([
            [$focal, 'forward_to_cenro_chief'], [$chief, 'receive_at_cenro_chief'],
            [$chief, 'forward_to_cenro_records'], [$cenroRecords, 'receive_at_cenro_records'],
            [$cenroRecords, 'forward_to_penro_records'], [$penroRecords, 'receive_at_penro_records'],
        ] as [$actor, $action]) routingPositionRegressionTransition('bms', $record, $action, $actor);

        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->firstOrFail();
        $eventCount = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count();
        $noticesBefore = DB::table('notifications')->where('notifiable_id', $destination['recipient']->id)->count();
        $gateway->rejectNextUpload();
        $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['bms', $record->id, $destination['key']]), [
            'stage' => $destination['key'],
            'official_document' => UploadedFile::fake()->createWithContent('rejected-replacement.pdf', "%PDF-1.4\nreplacement must roll back {$index}"),
        ])->assertRedirect()->assertSessionHasErrors('archive');

        expect($gateway->uploadAttempts())->toBe($index + 2)
            ->and(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->count())->toBe($eventCount)
            ->and(app(DocumentRoutingTransitionService::class)->state($record->fresh(), 'bms')['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS)
            ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->value('id'))->toBe($snapshot->getKey())
            ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->exists())->toBeFalse()
            ->and($record->fresh()->mov_file_path)->toBe($path)
            ->and($record->fresh()->mov_file_name)->toBe('Existing official report.pdf')
            ->and(Storage::disk('local')->exists($path))->toBeTrue()
            ->and(Storage::disk('local')->allFiles('current-documents'))->toBe([])
            ->and(DB::table('notifications')->where('notifiable_id', $destination['recipient']->id)->count())->toBe($noticesBefore);
    }
});

test('settings permissions, audit writes, boolean validation, and stale saves are enforced', function (): void {
    $viewAbility = Permission::findOrCreate('submission-tracking.routing-settings.view', 'web');
    $updateAbility = Permission::findOrCreate('submission-tracking.routing-settings.update', 'web');
    $viewOnly = User::factory()->create(['section' => 'CDS']);
    $viewOnly->givePermissionTo($viewAbility);
    $inertiaHeaders = ['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json'))];
    $this->actingAs($viewOnly)->withHeaders($inertiaHeaders)->get(route('settings.routing-workflow'))->assertOk();
    $this->actingAs($viewOnly)->put(route('settings.routing-workflow.update'), [
        'expected_version' => 1,
        'office_penro_enabled' => false,
        'penro_tsd_chief_enabled' => true,
    ])->assertForbidden();

    $roleOnly = User::factory()->create(['section' => 'CDS']);
    $roleOnly->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $this->actingAs($roleOnly)->withHeaders($inertiaHeaders)->get(route('settings.routing-workflow'))->assertForbidden();
    $this->actingAs($roleOnly)->put(route('settings.routing-workflow.update'), [
        'expected_version' => 1,
        'office_penro_enabled' => false,
        'penro_tsd_chief_enabled' => false,
    ])->assertForbidden();

    $admin = User::factory()->create(['section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $admin->givePermissionTo([$viewAbility, $updateAbility]);
    $this->actingAs($admin)->withHeaders($inertiaHeaders)->get(route('settings.routing-workflow'))->assertOk();

    $this->put(route('settings.routing-workflow.update'), [
        'expected_version' => 1,
        'office_penro_enabled' => false,
        'penro_tsd_chief_enabled' => true,
        'reason' => 'Approved test change',
    ])->assertSessionHasNoErrors();
    expect(RoutingPositionSettingVersion::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'Routing Position Settings Updated')->count())->toBe(1);

    $this->put(route('settings.routing-workflow.update'), [
        'expected_version' => 1,
        'office_penro_enabled' => true,
        'penro_tsd_chief_enabled' => false,
    ])->assertSessionHasErrors('expected_version');
    $this->put(route('settings.routing-workflow.update'), [
        'expected_version' => 2,
        'office_penro_enabled' => 'enabled',
        'penro_tsd_chief_enabled' => false,
    ])->assertSessionHasErrors('office_penro_enabled');
    expect(RoutingPositionSettingVersion::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'Routing Position Settings Updated')->count())->toBe(1);
});

test('the source registry and cutover watermarks cover exactly the ten active routing sources', function (): void {
    $expected = ['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans'];
    $tracking = app(SubmissionTrackingService::class);
    $watermarks = DB::table('routing_position_cutover_watermarks')->orderBy('source_key')->pluck('source_key')->all();
    $registered = collect($expected)->map(fn (string $key): string => $key)->all();
    sort($expected);
    expect($watermarks)->toBe($expected);
    foreach ($registered as $source) expect($tracking->source($source))->toBeArray();
    expect($tracking->source('technical-reports'))->toBeNull();

    foreach (['bms', 'bams'] as $source) {
        SubmissionRoutingSnapshot::query()->create([
            'source_key' => $source,
            'source_id' => 4242,
            'setting_version_id' => 1,
            'profile' => 'regular',
            'graph_version' => 'position-graph-v1',
            'captured_at' => now(),
        ]);
    }
    expect(SubmissionRoutingSnapshot::query()->where('source_id', 4242)->count())->toBe(2);
});

test('pre-cutover rows keep the all-enabled virtual baseline and broken snapshots fail closed', function (): void {
    $admin = User::factory()->create(['section' => 'CDS']);
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $settings = app(RoutingPositionSettingsService::class);
    $current = $settings->current();
    $settings->save($current['version'], false, false, 'Fallback fixture', $admin);

    $preCutover = positionControlReport('Precutover fallback PA');
    DB::table('routing_position_cutover_watermarks')->where('source_key', 'bms')->update(['max_id' => $preCutover->id]);
    $routing = app(DocumentRoutingTransitionService::class);
    $legacy = $routing->state($preCutover, 'bms');
    expect($legacy['route_position']['office_penro_enabled'])->toBeTrue()
        ->and($legacy['route_position']['penro_tsd_chief_enabled'])->toBeTrue()
        ->and($legacy['route_position']['preview'])->toBeFalse()
        ->and(collect($legacy['actions'])->pluck('key'))->toContain('forward_to_office_penro')
        ->and(SubmissionRoutingSnapshot::query()->where('source_id', $preCutover->id)->exists())->toBeFalse();

    $newRecord = positionControlReport('Broken snapshot PA');
    $version = RoutingPositionSettingVersion::query()->where('version', $settings->current()['version'])->firstOrFail();
    SubmissionRoutingSnapshot::query()->create([
        'source_key' => 'bms', 'source_id' => $newRecord->id, 'setting_version_id' => $version->id,
        'profile' => 'regular', 'graph_version' => 'unknown-graph', 'captured_at' => now(),
    ]);
    expect(fn () => $routing->state($newRecord, 'bms'))->toThrow(RuntimeException::class, 'captured route snapshot is invalid');
});

test('captured regular and direct profiles survive PA reclassification in normal and override archive dispatches', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $cenroChief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = routingPositionRegressionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $office = routingPositionRegressionActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    routingPositionRegressionSet($settingsAdmin, true, true);

    $area = ProtectedArea::query()->create([
        'name' => 'Captured profile consistency PA', 'short_name' => 'CPCP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);

    $routing = app(DocumentRoutingTransitionService::class);
    $cases = [
        ['captured' => 'regular', 'current' => 'direct', 'mode' => 'normal'],
        ['captured' => 'regular', 'current' => 'direct', 'mode' => 'override'],
        ['captured' => 'direct', 'current' => 'regular', 'mode' => 'normal'],
        ['captured' => 'direct', 'current' => 'regular', 'mode' => 'override'],
    ];

    foreach ($cases as $index => $case) {
        $area->forceFill($case['captured'] === 'direct'
            ? ['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS']
            : ['name' => 'Captured profile consistency PA', 'short_name' => 'CPCP'])
            ->save();
        app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);

        $record = routingPositionRegressionSourceRecord('bms', $owner, $area)['record'];
        if ($case['captured'] === 'regular') {
            foreach ([
                [$focal, 'forward_to_cenro_chief'],
                [$cenroChief, 'receive_at_cenro_chief'],
                [$cenroChief, 'forward_to_cenro_records'],
                [$cenroRecords, 'receive_at_cenro_records'],
                [$cenroRecords, 'forward_to_penro_records'],
                [$penroRecords, 'receive_at_penro_records'],
            ] as [$actor, $action]) routingPositionRegressionTransition('bms', $record, $action, $actor);
        } else {
            routingPositionRegressionTransition('bms', $record, 'receive_at_penro_records', $penroRecords);
        }

        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->firstOrFail();
        expect($snapshot->profile)->toBe($case['captured']);

        $area->forceFill($case['current'] === 'direct'
            ? ['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS']
            : ['name' => 'Captured profile consistency PA', 'short_name' => 'CPCP'])
            ->save();
        app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);

        $freshRecord = $record->fresh();
        expect(app(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class)
            ->isDirectPenro($freshRecord->load('protectedArea')))->toBe($case['current'] === 'direct');
        $state = $routing->state($freshRecord, 'bms');
        expect($state['route_profile'])->toBe($case['captured'])
            ->and(collect($state['actions'])->pluck('key'))->toContain('forward_to_office_penro')
            ->and($state['stage'])->toBe(DocumentRoutingProfileRegistry::PENRO_RECORDS);

        if ($case['mode'] === 'normal') {
            $event = routingPositionRegressionTransition('bms', $record, 'forward_to_office_penro', $penroRecords);
        } else {
            $event = $routing->transitionAsOverride($record, 'bms', 'forward_to_office_penro', $penroRecords, [
                'override_for_category' => OrganizationalAccessService::OFFICE_PENRO,
                'override_for_office' => 'PENRO Davao Oriental',
                'override_reason' => 'Captured profile regression '.$index,
            ], 'Captured profile regression '.$index);
            app(\App\Services\SubmissionTracking\RoutingTransitionLifecycle::class)->afterTransition($event, $penroRecords);
        }

        $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->where('logical_slot', 'mov')->firstOrFail();
        $context = collect($gateway->archiveContexts())->last();
        expect(data_get($event->metadata, 'route_profile'))->toBe($case['captured'])
            ->and(data_get($event->metadata, 'route_snapshot_id'))->toBe($snapshot->getKey())
            ->and($event->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO)
            ->and(data_get($event->metadata, 'administrative_override', false))->toBe($case['mode'] === 'override')
            ->and($archive->archive_status)->toBe('ARCHIVED')
            ->and(data_get($context, 'identity.source_type'))->toBe('bms')
            ->and((int) data_get($context, 'identity.source_id'))->toBe((int) $record->id)
            ->and(data_get($routing->state($record->fresh(), 'bms'), 'route_profile'))->toBe($case['captured'])
            ->and(routingPositionRegressionNotices('bms', (int) $record->id)->contains(fn ($notice): bool => (int) $notice->notifiable_id === (int) $office->id))->toBeTrue();
    }
});

test('captured regular profile keeps the existing PAMB MOV return gate after current PA policy becomes direct', function (): void {
    Storage::fake('local');
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $area = ProtectedArea::query()->create([
        'name' => 'PAMB captured MOV profile PA', 'short_name' => 'PCMP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);
    $report = routingPositionRegressionPambReport('regular_pamb', $owner, $area, 'captured-mov-gate');
    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_chief', $focal);
    routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_chief', $chief);

    $area->forceFill(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS'])->save();
    app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);
    $routing = app(DocumentRoutingTransitionService::class);
    $state = $routing->state($report->fresh(), 'conservation');
    $gate = new ReflectionMethod(DocumentRoutingTransitionService::class, 'pambMovAllowsAction');
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF)
        ->and($state['route_profile'])->toBe('regular')
        ->and($gate->invoke($routing, $report->fresh(), 'return_to_cenro_focal', $state['route_profile']))->toBeFalse()
        ->and($gate->invoke($routing, $report->fresh(), 'return_to_cenro_focal', 'direct'))->toBeTrue();
});

test('initial routing skip rows follow Office and TSD order for both regular and direct profiles', function (): void {
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $area = ProtectedArea::query()->create([
        'name' => 'Routing skip order PA', 'short_name' => 'RSOP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    $presenter = app(DocumentRoutingPresenter::class);
    $cases = [[true, true], [false, true], [true, false], [false, false]];

    foreach ([false, true] as $direct) {
        $area->forceFill($direct
            ? ['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS']
            : ['name' => 'Routing skip order PA', 'short_name' => 'RSOP'])
            ->save();
        app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);

        foreach ($cases as [$officeEnabled, $tsdEnabled]) {
            routingPositionRegressionSet($settingsAdmin, $officeEnabled, $tsdEnabled);
            $record = routingPositionRegressionSourceRecord('bms', $owner, $area)['record'];
            $presented = $presenter->present($record, 'bms');
            $timeline = collect($presented['timeline']);
            $keys = $timeline->pluck('key')->all();
            $index = fn (string $key): int|false => array_search($key, $keys, true);
            expect($presented['profile_key'])->toBe($direct ? 'canonical_direct_penro' : 'canonical_cenro_penro_regional');

            if ($officeEnabled && $tsdEnabled) {
                expect($keys)->not->toContain('office_initial_skipped', 'tsd_initial_skipped');
            } elseif ($officeEnabled) {
                expect($index(DocumentRoutingProfileRegistry::OFFICE_PENRO))->toBeLessThan($index('tsd_initial_skipped'))
                    ->and($index('tsd_initial_skipped'))->toBeLessThan($index(DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL));
            } elseif ($tsdEnabled) {
                expect($index(DocumentRoutingProfileRegistry::PENRO_RECORDS))->toBeLessThan($index('office_initial_skipped'))
                    ->and($index('office_initial_skipped'))->toBeLessThan($index(DocumentRoutingProfileRegistry::TRANSIT_TSD));
            } else {
                expect($index(DocumentRoutingProfileRegistry::PENRO_RECORDS))->toBeLessThan($index('office_initial_skipped'))
                    ->and($index('office_initial_skipped'))->toBeLessThan($index('tsd_initial_skipped'))
                    ->and($index('tsd_initial_skipped'))->toBeLessThan($index(DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL));
            }

            if (! $officeEnabled) {
                expect($index('office_final_skipped'))->toBeLessThan($index(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL));
            }

            $skips = $timeline->filter(fn (array $row): bool => in_array($row['key'], ['office_initial_skipped', 'tsd_initial_skipped', 'office_final_skipped'], true));
            expect($skips->every(fn (array $row): bool => $row['status'] === 'skipped' && $row['occurred_at'] === null && $row['recorded_by'] === null))->toBeTrue()
                ->and(collect($presented['actions'])->pluck('key')->intersect($skips->pluck('key'))->all())->toBeEmpty();
        }
    }
});

test('captured regular PAMB profile keeps CENRO visibility and MOV review after PA reclassification for every meeting workflow', function (): void {
    Storage::fake('local');
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $wrongOfficeChief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Davao City');
    $records = routingPositionRegressionActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    routingPositionRegressionSet($settingsAdmin, true, true);

    $area = ProtectedArea::query()->create([
        'name' => 'Captured PAMB access PA', 'short_name' => 'CPAP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);
    $otherArea = ProtectedArea::query()->create([
        'name' => 'Other PAMB access PA', 'short_name' => 'OPAP', 'category' => 'Protected Landscape',
        'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    $wrongPaPamo = routingPositionRegressionActor(OrganizationalAccessService::PAMO, 'PAMO', (int) $otherArea->id);
    $noPermissionPamo = routingPositionRegressionActor(OrganizationalAccessService::PAMO, 'PAMO', (int) $area->id);
    $noPermissionPamo->revokePermissionTo('submission-tracking.view');

    $workflowCount = 0;
    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $workflowCount++;
        $area->forceFill(['name' => 'Captured PAMB access PA', 'short_name' => 'CPAP'])->save();
        app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);
        $report = routingPositionRegressionPambReport($workflow, $owner, $area, 'captured-access');
        $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_chief', $focal);
        routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_chief', $chief);
        expect(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->value('profile'))
            ->toBe('regular');

        $area->forceFill(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS'])->save();
        app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);
        $routing = app(DocumentRoutingTransitionService::class);
        expect(data_get($routing->state($report->fresh(), 'conservation'), 'route_profile'))->toBe('regular')
            ->and(app(PambMovProcessingService::class)->status($report->fresh()))->toBe(PambMovProcessingService::SUBMITTED_FOR_REVIEW);

        $custodyReadState = function () use ($report): array {
            $fresh = $report->fresh();
            return [
                'events' => DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->get()->toArray(),
                'dates' => [
                    $fresh->getRawOriginal('date_report_released_cenro'),
                    $fresh->getRawOriginal('date_received_penro'),
                    $fresh->getRawOriginal('date_endorsed_regional'),
                ],
                'snapshot' => SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->firstOrFail()->getAttributes(),
                'mov' => [$fresh->mov_processing_status, app(PambMovProcessingService::class)->status($fresh)],
                'archives' => DocumentArchive::query()->where('source_type', 'conservation')->where('source_id', $report->id)->get()->toArray(),
            ];
        };
        $custodyBeforeReads = $custodyReadState();

        $pendingCenro = $this->actingAs($chief)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
            'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        $pendingCenroRow = collect(data_get($pendingCenro, 'workspaceQueues.incoming', []))
            ->first(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === (int) $report->id);
        expect($pendingCenroRow)->not->toBeNull()
            ->and($pendingCenroRow['submission_status'])->toBe(\App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO)
            ->and(data_get($pendingCenro, 'pagination'))->toMatchArray([
                'current_page' => 1,
                'per_page' => 25,
                'total' => $workflowCount,
                'last_page' => 1,
                'from' => 1,
                'to' => $workflowCount,
                'has_more' => false,
            ]);

        $selectedPendingCenro = $this->actingAs($chief)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
            'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        expect(data_get($selectedPendingCenro, 'trackingContext.selected_record.source'))->toBe('conservation')
            ->and((int) data_get($selectedPendingCenro, 'trackingContext.selected_record.source_id'))->toBe((int) $report->id)
            ->and(data_get($selectedPendingCenro, 'trackingContext.selected_record.submission_status'))->toBe(\App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO);

        $pendingPenro = $this->actingAs($chief)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_PENRO,
            'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        $selectedPendingPenro = $this->actingAs($chief)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_PENRO,
            'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        expect(data_get($pendingPenro, 'pagination.total'))->toBe(0)
            ->and(data_get($selectedPendingPenro, 'pagination.total'))->toBe(0)
            ->and(data_get($selectedPendingPenro, 'trackingContext.selected_record'))->toBeNull();

        $wrongOfficeFiltered = $this->actingAs($wrongOfficeChief)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
            'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        $wrongPaFiltered = $this->actingAs($wrongPaPamo)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
            'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        $this->actingAs($noPermissionPamo)->get(route('submission-tracking.index', [
            'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
        ]))->assertForbidden();
        expect(data_get($wrongOfficeFiltered, 'trackingContext.selected_record'))->toBeNull()
            ->and(data_get($wrongPaFiltered, 'trackingContext.selected_record'))->toBeNull()
            ->and($custodyReadState())->toBe($custodyBeforeReads);

        $workspace = $this->actingAs($chief)->get(route('submission-tracking.index'))->assertOk()->inertiaProps();
        $incoming = collect(data_get($workspace, 'workspaceQueues.incoming', []))->first(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === (int) $report->id);
        expect($incoming)->not->toBeNull()
            ->and(data_get($incoming, 'pamb_action_flags.can_review'))->toBeTrue();

        $selected = $this->actingAs($chief)->get(route('submission-tracking.index', [
            'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
        ]))->assertOk()->inertiaProps();
        expect(data_get($selected, 'trackingContext.selected_record.source'))->toBe('conservation')
            ->and((int) data_get($selected, 'trackingContext.selected_record.source_id'))->toBe((int) $report->id);

        $eventsBefore = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
        $this->actingAs($chief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'forward_to_cenro_records']), ['stage' => 'forward_to_cenro_records'])
            ->assertRedirect()->assertSessionHasErrors('stage');
        expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($eventsBefore);

        $this->actingAs($wrongOfficeChief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
            'decision' => PambMovProcessingService::READY_FOR_RELEASE,
        ])->assertForbidden();
        $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
            'decision' => PambMovProcessingService::READY_FOR_RELEASE,
        ])->assertRedirect()->assertSessionHasNoErrors();
        expect($report->fresh()->mov_processing_status)->toBe(PambMovProcessingService::READY_FOR_RELEASE)
            ->and(app(PambMovProcessingService::class)->present($report->fresh())['cenro_review']['applicable'])->toBeTrue();

        routingPositionRegressionTransition('conservation', $report, 'forward_to_cenro_records', $chief);
        routingPositionRegressionTransition('conservation', $report, 'receive_at_cenro_records', $records);
    }

    // An unstarted record continues to use current PA policy, even after a
    // different report has captured the regular graph.
    $newDirect = routingPositionRegressionPambReport('regular_pamb', $owner, $area, 'new-current-direct');
    expect(data_get(app(DocumentRoutingTransitionService::class)->state($newDirect, 'conservation'), 'route_profile'))->toBe('direct')
        ->and(app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class)->canView($focal, $newDirect))->toBeFalse();
    $hiddenDirect = $this->actingAs($focal)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $newDirect->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    expect(data_get($hiddenDirect, 'trackingContext.selected_record'))->toBeNull();

    $directStatus = $this->actingAs($penroRecords)->get(route('submission-tracking.index', [
        'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_PENRO,
        'source' => 'conservation', 'source_id' => $newDirect->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $cenroStatus = $this->actingAs($penroRecords)->get(route('submission-tracking.index', [
        'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
        'source' => 'conservation', 'source_id' => $newDirect->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    expect(data_get($directStatus, 'pagination.total'))->toBe(1)
        ->and(data_get($directStatus, 'trackingContext.selected_record.submission_status'))->toBe(\App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_PENRO)
        ->and(data_get($cenroStatus, 'pagination.total'))->toBe(3)
        ->and(data_get($cenroStatus, 'trackingContext.selected_record'))->toBeNull()
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $newDirect->id)->exists())->toBeFalse();
});

test('captured direct PAMB route stays outside CENRO work after PA reclassification while new regular routes remain eligible', function (): void {
    Storage::fake('local');
    $owner = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $area = ProtectedArea::query()->create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);
    $report = routingPositionRegressionPambReport('regular_pamb', $owner, $area, 'captured-direct-access');
    routingPositionRegressionTransition('conservation', $report, 'receive_at_penro_records', $penroRecords);
    expect(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $report->id)->value('profile'))
        ->toBe('direct');

    $area->forceFill(['name' => 'Reclassified Regular PA', 'short_name' => 'RRPA'])->save();
    app()->forgetInstance(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class);
    $routing = app(DocumentRoutingTransitionService::class);
    $mov = app(PambMovProcessingService::class)->present($report->fresh());
    expect(data_get($routing->state($report->fresh(), 'conservation'), 'route_profile'))->toBe('direct')
        ->and($mov['status_key'])->toBe(PambMovProcessingService::RECEIVED_BY_PENRO)
        ->and($mov['cenro_review']['applicable'])->toBeFalse()
        ->and(app(\App\Services\SubmissionTracking\PambSubmissionAccessService::class)->canPerformForSubmission($chief, 'review', $report->fresh()))->toBeFalse();

    $chiefWorkspace = $this->actingAs($chief)->get(route('submission-tracking.index'))->assertOk()->inertiaProps();
    expect(collect(data_get($chiefWorkspace, 'workspaceQueues.incoming', []))->contains(fn (array $row): bool => $row['source'] === 'conservation' && (int) $row['source_id'] === (int) $report->id))->toBeFalse();
    $hiddenDirect = $this->actingAs($chief)->get(route('submission-tracking.index', [
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    expect(data_get($hiddenDirect, 'trackingContext.selected_record'))->toBeNull();
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), [
        'decision' => PambMovProcessingService::READY_FOR_RELEASE,
    ])->assertForbidden();
    expect(app(PambMovProcessingService::class)->status($report->fresh()))->toBe(PambMovProcessingService::RECEIVED_BY_PENRO);

    $reversePendingRegional = $this->actingAs($penroRecords)->get(route('submission-tracking.index', [
        'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_REGIONAL,
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    $reversePendingCenro = $this->actingAs($penroRecords)->get(route('submission-tracking.index', [
        'status' => \App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_CENRO,
        'source' => 'conservation', 'source_id' => $report->id, 'view' => 'incoming',
    ]))->assertOk()->inertiaProps();
    expect(data_get($reversePendingRegional, 'pagination.total'))->toBe(1)
        ->and(data_get($reversePendingRegional, 'trackingContext.selected_record.submission_status'))->toBe(\App\Services\SubmissionTracking\RoutingStatusPresenter::PENDING_REGIONAL)
        ->and(data_get($reversePendingCenro, 'pagination.total'))->toBe(0)
        ->and(data_get($reversePendingCenro, 'trackingContext.selected_record'))->toBeNull();

    $newRegular = routingPositionRegressionPambReport('regular_pamb', $owner, $area, 'new-current-regular');
    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $newRegular->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    routingPositionRegressionTransition('conservation', $newRegular, 'forward_to_cenro_chief', $focal);
    expect(SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $newRegular->id)->value('profile'))
        ->toBe('regular');
});

test('direct initial Records correction rejects a missing sender before writing an incompatible handoff', function (): void {
    Storage::fake('local');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $wrongCategoryPenroFocal = routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $area = ProtectedArea::query()->create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $penroRecords->id, 'updated_by' => $penroRecords->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $penroRecords->id,
    ]);
    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $pamb = routingPositionRegressionPambReport('regular_pamb', $owner, $area, 'missing-direct-sender');
    $generic = routingPositionRegressionSourceRecord('bms', $owner, $area)['record'];

    foreach ([['conservation', $pamb], ['bms', $generic]] as [$source, $record]) {
        $eventsBefore = DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $record->id)->count();
        $auditBefore = AuditLog::query()->count();
        $notificationBefore = routingPositionRegressionNotices($source, (int) $record->id)->count();
        $snapshotBefore = SubmissionRoutingSnapshot::query()->where('source_key', $source)->where('source_id', $record->id)->count();
        $archiveBefore = DocumentArchive::query()->where('source_type', $source)->where('source_id', $record->id)->get()->toArray();
        $attachmentsBefore = DB::table('submission_routing_attachments')->where('source', $source)->where('source_id', $record->id)->get()->toArray();
        $datesBefore = [$record->date_report_released_cenro, $record->date_received_penro, $record->date_endorsed_regional];
        $queueRows = function () use ($source, $record): array {
            $queues = app(SubmissionTrackingService::class)->workspaceQueues(['program' => 'conservation']);
            return collect($queues)->map(fn ($rows) => collect($rows)->first(fn (array $row): bool => ($row['source'] ?? null) === $source && (int) ($row['source_id'] ?? 0) === (int) $record->id))->all();
        };
        $this->actingAs($penroRecords);
        $queuesBefore = $queueRows();

        $this->actingAs($wrongCategoryPenroFocal)->post(route('submission-tracking.transition', [$source, $record->id, 'return_for_correction_penro_records']), [
            'stage' => 'return_for_correction_penro_records',
            'correction_reason_key' => 'incomplete_document',
        ])->assertForbidden();

        $this->actingAs($penroRecords)->post(route('submission-tracking.transition', [$source, $record->id, 'return_for_correction_penro_records']), [
            'stage' => 'return_for_correction_penro_records',
            'correction_reason_key' => 'incomplete_document',
            'correction_detail' => 'Identify the authorized previous sender.',
        ])->assertRedirect()->assertSessionHasErrors(['stage' => 'A correction return cannot be recorded because no verified sender is recorded in the captured direct route.']);

        $fresh = $record->fresh();
        expect(DocumentRoutingEvent::query()->where('source_type', $source)->where('source_id', $record->id)->count())->toBe($eventsBefore)
            ->and(SubmissionRoutingSnapshot::query()->where('source_key', $source)->where('source_id', $record->id)->count())->toBe($snapshotBefore)
            ->and(DocumentArchive::query()->where('source_type', $source)->where('source_id', $record->id)->get()->toArray())->toEqual($archiveBefore)
            ->and(DB::table('submission_routing_attachments')->where('source', $source)->where('source_id', $record->id)->get()->toArray())->toEqual($attachmentsBefore)
            ->and(AuditLog::query()->count())->toBe($auditBefore)
            ->and(routingPositionRegressionNotices($source, (int) $record->id)->count())->toBe($notificationBefore)
            ->and($queueRows())->toEqual($queuesBefore)
            ->and([$fresh->date_report_released_cenro, $fresh->date_received_penro, $fresh->date_endorsed_regional])->toBe($datesBefore)
            ->and($fresh->getAttribute('mov_file_path'))->toBe($record->getAttribute('mov_file_path'));
    }
});

test('stale position-dependent actions use effective-graph authorization before stage validation', function (): void {
    Storage::fake('local');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    $wrongCategory = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $settings = app(RoutingPositionSettingsService::class);
    $resolver = app(EffectiveRoutingGraphResolver::class);
    $routing = app(DocumentRoutingTransitionService::class);
    $tracking = app(SubmissionTrackingService::class);

    $cases = [
        ['key' => 'dispatch_penro_records_to_tsd', 'office' => false, 'tsd' => true, 'actor' => OrganizationalAccessService::PENRO_RECORDS, 'from' => DocumentRoutingProfileRegistry::PENRO_RECORDS, 'to' => DocumentRoutingProfileRegistry::TRANSIT_TSD],
        ['key' => 'dispatch_penro_records_to_cds_focal', 'office' => false, 'tsd' => false, 'actor' => OrganizationalAccessService::PENRO_RECORDS, 'from' => DocumentRoutingProfileRegistry::PENRO_RECORDS, 'to' => DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL],
        ['key' => 'dispatch_office_to_cds_focal', 'office' => true, 'tsd' => false, 'actor' => OrganizationalAccessService::OFFICE_PENRO, 'from' => DocumentRoutingProfileRegistry::OFFICE_PENRO, 'to' => DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL],
        ['key' => 'recommend_to_penro_records_final', 'office' => false, 'tsd' => true, 'actor' => OrganizationalAccessService::PENRO_CHIEF, 'from' => DocumentRoutingProfileRegistry::CDS_CHIEF, 'to' => DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL],
    ];

    foreach ($cases as $index => $case) {
        $version = $settings->current();
        $version = routingPositionRegressionSet($settingsAdmin, $case['office'], $case['tsd']);
        $versionRecordId = \App\Models\RoutingPositionSetting::query()->whereKey(1)->value('setting_version_id');
        $actor = routingPositionRegressionActor($case['actor'], 'PENRO Davao Oriental');
        $record = positionControlReport('Stale effective action '.$index);
        $position = [
            'setting_version_id' => (int) $versionRecordId,
            'version' => (int) $version['version'],
            'office_penro_enabled' => (bool) $version['office_penro_enabled'],
            'penro_tsd_chief_enabled' => (bool) $version['penro_tsd_chief_enabled'],
            'graph_version' => \App\Services\SubmissionTracking\RoutingPositionSnapshotService::GRAPH_VERSION,
            'preview' => false,
            'needs_capture' => false,
            'origin' => 'snapshot',
            'profile' => 'regular',
        ];
        $effectiveAction = collect($resolver->resolve('bms', false, $position)['actions'])->firstWhere('key', $case['key']);
        expect($effectiveAction)->toBeArray();

        SubmissionRoutingSnapshot::query()->create([
            'source_key' => 'bms', 'source_id' => $record->id, 'setting_version_id' => $versionRecordId,
            'profile' => 'regular', 'graph_version' => 'position-graph-v1', 'captured_at' => now(),
        ]);
        DocumentRoutingEvent::query()->create([
            'source_type' => 'bms', 'source_id' => $record->id, 'workflow_key' => 'bms',
            'event_key' => $effectiveAction['event_key'], 'from_stage' => $case['from'], 'to_stage' => $case['to'],
            'from_office' => $effectiveAction['from_office'], 'to_office' => $effectiveAction['to_office'],
            'occurred_at' => now(), 'recorded_by' => $actor->id, 'metadata' => ['action_key' => $case['key']],
        ]);
        $stateBefore = $routing->state($record->fresh(), 'bms');
        expect($stateBefore['stage'])->toBe($case['to'])
            ->and(collect($stateBefore['route_actions'])->pluck('key'))->toContain($case['key']);

        $queueSignature = function () use ($tracking, $record): array {
            $queues = $tracking->workspaceQueues(['program' => 'conservation']);
            return collect($queues)->map(fn ($rows) => collect($rows)->first(fn (array $row): bool => ($row['source'] ?? null) === 'bms' && (int) ($row['source_id'] ?? 0) === (int) $record->id))->all();
        };
        $this->actingAs($actor);
        $queuesBefore = $queueSignature();
        $eventsBefore = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->get()->toArray();
        $snapshotBefore = SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->first()->toArray();
        $recordBefore = $record->fresh()->getAttributes();
        $archiveBefore = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->get()->toArray();
        $auditBefore = AuditLog::query()->count();
        $noticeBefore = routingPositionRegressionNotices('bms', (int) $record->id)->count();
        $filesBefore = Storage::disk('local')->allFiles();

        $wrongOfficeActor = routingPositionRegressionActor($case['actor'], 'CENRO Mati');
        $wrongSourceActor = User::factory()->create([
            'section' => $case['actor'],
            'office_designated' => 'PENRO Davao Oriental',
            'unit_assignment' => OrganizationalAccessService::CONSERVATION,
            'protected_area_id' => $record->protected_area_id,
        ]);
        $wrongSourceActor->givePermissionTo(Permission::findOrCreate('submission-tracking.view', 'web'));

        $this->actingAs($wrongCategory)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => $case['key']]), ['stage' => $case['key']])
            ->assertForbidden();
        $this->actingAs($wrongOfficeActor)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => $case['key']]), ['stage' => $case['key']])
            ->assertForbidden();
        $this->actingAs($wrongSourceActor)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => $case['key']]), ['stage' => $case['key']])
            ->assertForbidden();
        $this->actingAs($actor)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => $case['key']]), ['stage' => $case['key']])
            ->assertRedirect()->assertSessionHasErrors('stage');

        $queuesAfter = $queueSignature();
        expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->get()->toArray())->toEqual($eventsBefore)
            ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->id)->first()->toArray())->toEqual($snapshotBefore)
            ->and($record->fresh()->getAttributes())->toEqual($recordBefore)
            ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->get()->toArray())->toEqual($archiveBefore)
            ->and(AuditLog::query()->count())->toBe($auditBefore)
            ->and(routingPositionRegressionNotices('bms', (int) $record->id)->count())->toBe($noticeBefore)
            ->and(Storage::disk('local')->allFiles())->toBe($filesBefore)
            ->and($queuesAfter)->toEqual($queuesBefore)
            ->and($routing->state($record->fresh(), 'bms')['stage'])->toBe($case['to']);
    }
});

test('direct Records correction returns only to a sender verified by the captured route graph', function (): void {
    Storage::fake('local');
    $penroRecords = routingPositionRegressionActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $penroFocal = routingPositionRegressionActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental');
    $record = positionControlReport('Mt. Hamiguitan Range Wildlife Sanctuary');
    $versionId = \App\Models\RoutingPositionSetting::query()->whereKey(1)->value('setting_version_id');
    SubmissionRoutingSnapshot::query()->create([
        'source_key' => 'bms', 'source_id' => $record->id, 'setting_version_id' => $versionId,
        'profile' => 'direct', 'graph_version' => 'position-graph-v1', 'captured_at' => now(),
    ]);
    $sender = collect(app(DocumentRoutingProfileRegistry::class)->actionProfile('bms', true)['actions'])
        ->firstWhere('key', 'forward_from_penro_origin');
    expect($sender)->toBeArray();
    DocumentRoutingEvent::query()->create([
        'source_type' => 'bms', 'source_id' => $record->id, 'workflow_key' => 'bms',
        'event_key' => $sender['event_key'], 'from_stage' => $sender['from'], 'to_stage' => $sender['to'],
        'from_office' => $sender['from_office'], 'to_office' => $sender['to_office'],
        'occurred_at' => now(), 'recorded_by' => $penroFocal->id, 'metadata' => ['action_key' => $sender['key']],
    ]);

    $routing = app(DocumentRoutingTransitionService::class);
    expect($routing->state($record->fresh(), 'bms')['route_profile'])->toBe('direct')
        ->and(collect($routing->state($record->fresh(), 'bms')['actions'])->pluck('key'))->toContain('return_for_correction_penro_records');

    $this->actingAs($penroRecords)->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $record->id, 'stage' => 'return_for_correction_penro_records']), [
        'stage' => 'return_for_correction_penro_records',
        'remarks' => 'Please correct the missing endorsement.',
        'correction_reason_key' => 'missing_endorsement',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $returned = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->id)->latest('id')->firstOrFail();
    expect($returned->to_stage)->toBe(DocumentRoutingProfileRegistry::PENRO_ORIGIN)
        ->and($returned->to_office)->toBe($sender['from_office']);

    $correctionState = $routing->state($record->fresh(), 'bms');
    expect(collect($correctionState['actions'])->firstWhere('key', 'receive_correction')['categories'])
        ->toBe([OrganizationalAccessService::PENRO_FOCAL]);
    routingPositionRegressionTransition('bms', $record, 'receive_correction', $penroFocal);
    expect(collect($routing->state($record->fresh(), 'bms')['actions'])->pluck('key'))
        ->toContain('forward_from_penro_origin');
    $resubmission = routingPositionRegressionTransition('bms', $record, 'forward_from_penro_origin', $penroFocal);
    expect($resubmission->from_stage)->toBe(DocumentRoutingProfileRegistry::PENRO_ORIGIN)
        ->and($resubmission->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS);
});

test('ordinary forward action stays next while correction remains optional across every active routing source', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    Storage::fake('local');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $owner = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $focal = routingPositionRegressionActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = routingPositionRegressionActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $settingsAdmin = User::factory()->create(['section' => 'CDS']);
    $settingsAdmin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));
    routingPositionRegressionSet($settingsAdmin, true, true);

    $area = ProtectedArea::query()->create([
        'name' => 'Next action active source PA', 'short_name' => 'NAAS', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $owner->id,
    ]);

    $developmentActors = [];
    foreach ([OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF] as $category) {
        $actor = User::factory()->create([
            'section' => $category,
            'office_designated' => 'CENRO Mati',
            'unit_assignment' => OrganizationalAccessService::DEVELOPMENT,
            'is_active' => true,
            'is_approved' => true,
        ]);
        $actor->givePermissionTo([
            Permission::findOrCreate('reports.view', 'web'),
            Permission::findOrCreate('submission-tracking.view', 'web'),
            Permission::findOrCreate('technical-reports.update', 'web'),
        ]);
        $developmentActors[$category] = $actor;
    }

    $sources = ['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans'];
    foreach ($sources as $source) {
        $fixture = routingPositionRegressionSourceRecord($source, $owner, $area);
        $record = $fixture['record'];
        $sourceFocal = $source === 'engp' ? $developmentActors[OrganizationalAccessService::CENRO_FOCAL] : $focal;
        $sourceChief = $source === 'engp' ? $developmentActors[OrganizationalAccessService::CENRO_CHIEF] : $chief;

        routingPositionRegressionTransition($source, $record, 'forward_to_cenro_chief', $sourceFocal);
        routingPositionRegressionTransition($source, $record, 'receive_at_cenro_chief', $sourceChief);

        $events = app(DocumentRoutingTransitionService::class)->events($record->fresh(), $source);
        $presented = app(DocumentRoutingPresenter::class)->present($record->fresh(), $source, null, $events);
        expect($presented['current_stage'])->toBe(DocumentRoutingProfileRegistry::CENRO_CHIEF)
            ->and($presented['next_expected_action'])->toBe('Forward to CENRO Records')
            ->and(collect($presented['actions'])->pluck('key'))->toContain('return_to_cenro_focal', 'forward_to_cenro_records');
    }
});

test('locked route position resolution sees a committed version newer than its consistent read under mysql repeatable read', function (): void {
    $default = DB::connection();
    $databaseName = (string) config('database.connections.'.config('database.default').'.database');
    if ($default->getDriverName() !== 'mysql'
        || app()->environment() !== 'testing'
        || ! str_starts_with($databaseName, 'cds_routing_uat_')) {
        $this->markTestSkipped('This regression only runs against the isolated disposable MySQL safety database.');
    }

    $head = DB::table('routing_position_settings')->where('id', 1)->first();
    $before = DB::table('routing_position_setting_versions')->where('id', $head->setting_version_id)->first();
    $baseVersion = (int) $before->version;
    $record = new BmsReportSubmission();
    $record->setAttribute('id', (int) DB::table('routing_position_cutover_watermarks')->where('source_key', 'bms')->value('max_id') + 1000);

    // Establish this connection's InnoDB consistent-read view before the
    // independent writer publishes a new head and immutable version.
    DB::table('routing_position_setting_versions')->where('id', $before->id)->first();

    $connectionName = 'routing_position_settings_probe';
    config(['database.connections.'.$connectionName => config('database.connections.'.config('database.default'))]);
    DB::purge($connectionName);
    $writer = DB::connection($connectionName);
    $writerIdentity = $writer->selectOne('SELECT DATABASE() AS database_name, @@port AS port');
    expect($writerIdentity->database_name)->toBe($databaseName)
        ->and((string) $writerIdentity->port)->toBe('3306');

    $nextVersion = (int) $writer->table('routing_position_setting_versions')->max('version') + 1;
    expect($nextVersion)->toBe($baseVersion + 1);
    $office = ! (bool) $before->office_penro_enabled;
    $tsd = ! (bool) $before->penro_tsd_chief_enabled;
    $now = now();
    $newVersionId = null;

    try {
        $writer->beginTransaction();
        $newVersionId = $writer->table('routing_position_setting_versions')->insertGetId([
            'version' => $nextVersion,
            'office_penro_enabled' => $office,
            'penro_tsd_chief_enabled' => $tsd,
            'saved_by' => null,
            'saved_at' => $now,
            'reason' => 'MySQL repeatable-read route snapshot regression',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $writer->table('routing_position_settings')->where('id', 1)->update([
            'setting_version_id' => $newVersionId,
            'updated_at' => $now,
        ]);
        $writer->commit();

        $position = app(\App\Services\SubmissionTracking\RoutingPositionSnapshotService::class)
            ->resolve($record, 'bms', false, true);

        expect($position['version'])->toBe($nextVersion)
            ->and($position['setting_version_id'])->toBe($newVersionId)
            ->and($position['office_penro_enabled'])->toBe($office)
            ->and($position['penro_tsd_chief_enabled'])->toBe($tsd);
    } finally {
        // Release the test transaction's settings-head lock before restoring
        // the committed fixture through the independent writer connection.
        if ($writer->transactionLevel() > 0) $writer->rollBack();
        while ($default->transactionLevel() > 0) $default->rollBack();
        if ($newVersionId !== null) {
            $writer->table('routing_position_settings')->where('id', 1)->update([
                'setting_version_id' => $head->setting_version_id,
                'updated_at' => now(),
            ]);
            $writer->table('routing_position_setting_versions')->where('id', $newVersionId)->delete();
        }
        DB::purge($connectionName);
    }
});
