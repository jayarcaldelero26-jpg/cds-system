<?php

use App\Domain\Modules\ProgramArea;
use App\Mail\OverdueComplianceMemorandum;
use App\Models\ComplianceAlertRecipient;
use App\Models\ComplianceNotificationRun;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\EngpReportSubmission;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\BusinessCalendarService;
use App\Services\Compliance\ComplianceAlertDeliveryService;
use App\Services\Compliance\OverdueReportService;
use App\Services\SubmissionTracking\AdminRoutingOverrideService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\OfficeTatProcessedDocumentSourceService;
use App\Services\Reports\ReportRequirementRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function dynamicModuleUser(string $section, string $office): User
{
    $user = User::factory()->create(['section' => $section, 'unit_assignment' => null, 'office_designated' => $office]);
    foreach (['reports.view', 'technical-reports.view', 'technical-reports.create', 'technical-reports.update', 'office-tat.view', 'submission-tracking.view', 'final-archives.view'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    return $user;
}

function dynamicModuleRouteToRegional(string $source, int $recordId, string $unit, User $focal): User
{
    $penro = 'PENRO Davao Oriental';
    $actor = function (string $category, string $office) use ($unit): User {
        $user = dynamicModuleUser($category, $office);
        $user->forceFill(['unit_assignment' => $unit])->save();
        return $user;
    };
    $actors = [
        'records' => $actor(OrganizationalAccessService::PENRO_RECORDS, $penro),
        'office' => $actor(OrganizationalAccessService::OFFICE_PENRO, $penro),
        'tsd' => $actor(OrganizationalAccessService::PENRO_TSD_CHIEF, $penro),
        'focal' => $actor(OrganizationalAccessService::PENRO_FOCAL, $penro),
        'chief' => $actor(OrganizationalAccessService::PENRO_CHIEF, $penro),
    ];
    $focal->forceFill(['unit_assignment' => $unit])->save();
    $tracking = app(SubmissionTrackingService::class);

    foreach ([
        ['records', 'forward_to_office_penro'],
        ['office', 'receive_at_office_penro'],
        ['office', 'assign_to_tsd_chief'],
        ['tsd', 'receive_at_tsd_chief'],
        ['tsd', 'forward_to_cds_focal'],
        ['focal', 'receive_at_cds_focal'],
        ['focal', 'forward_to_cds_chief'],
        ['chief', 'receive_at_cds_chief'],
        ['chief', 'recommend_to_office_penro'],
        ['office', 'receive_at_office_penro_final'],
        ['office', 'approve_for_regional_release'],
        ['records', 'receive_at_penro_records_final'],
        ['records', 'release_to_regional'],
    ] as [$actorKey, $action]) {
        $tracking->transition($source, $recordId, $action, null, $actors[$actorKey]->id);
    }

    return $actors['focal'];
}

function dynamicModuleManager(): User
{
    $user = User::factory()->create(['section' => 'PENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'PENRO Davao Oriental']);
    foreach (['module-definitions.view', 'module-definitions.create', 'module-definitions.update', 'module-definitions.activate', 'technical-reports.view'] as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    return $user;
}

function dynamicModulePayload(string $name, string $unit, string $type, array $extra = []): array
{
    return [
        'unit' => $unit,
        'name' => $name,
        'program_area' => $unit === 'development' ? ProgramArea::ENGP->value : ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT->value,
        'module_type' => $type,
        'reporting_frequency' => 'custom',
        'activity_name' => $name.' Activity',
        'document_types' => 'Progress Report, Final Report',
        'period_labels' => "Current Period | 2026-07-01 | 2026-10-27\nFuture Period 2027 | 2027-01-01 | 2027-12-31",
        'reminder_offices' => $unit === 'development' ? ['CENRO Baganga'] : [],
        'deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS,
        'default_deadline_days' => 1,
        'allow_deadline_override' => false,
        'description' => 'Dynamic module flow fixture.',
        'is_active' => true,
        ...$extra,
    ];
}

function dynamicModuleRouteToPenro(string $source, int $recordId, string $office, User $focal): void
{
    $actors = [
        'focal' => $focal,
        'chief' => dynamicModuleUser(OrganizationalAccessService::CENRO_CHIEF, $office),
        'records' => dynamicModuleUser(OrganizationalAccessService::CENRO_RECORDS, $office),
        'penro' => dynamicModuleUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    $tracking = app(SubmissionTrackingService::class);

    foreach ([
        ['focal', 'forward_to_cenro_chief'],
        ['chief', 'receive_at_cenro_chief'],
        ['chief', 'forward_to_cenro_records'],
        ['records', 'receive_at_cenro_records'],
        ['records', 'forward_to_penro_records'],
        ['penro', 'receive_at_penro_records'],
    ] as [$actorKey, $action]) {
        test()->actingAs($actors[$actorKey]);
        $tracking->transition($source, $recordId, $action, null, $actors[$actorKey]->id);
    }
}

test('four configured unit and module type combinations use dynamic registries, entry routes, routing, and processed TAT details', function (): void {
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $manager = dynamicModuleManager();
    $conservationFocal = dynamicModuleUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $developmentFocal = dynamicModuleUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $area = ProtectedArea::create([
        'name' => 'Dynamic Flow Test PA', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $conservationFocal->id, 'updated_by' => $conservationFocal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $conservationFocal->id,
    ]);

    $cases = [
        ['name' => 'QA Flow Conservation Regular', 'unit' => 'conservation', 'type' => ModuleDefinition::TYPE_REGULAR_TARGET],
        ['name' => 'QA Flow Conservation Plan', 'unit' => 'conservation', 'type' => ModuleDefinition::TYPE_PLAN],
        ['name' => 'QA Flow Development Regular', 'unit' => 'development', 'type' => ModuleDefinition::TYPE_REGULAR_TARGET],
        ['name' => 'QA Flow Development Plan', 'unit' => 'development', 'type' => ModuleDefinition::TYPE_PLAN],
    ];
    $definitions = [];

    foreach ($cases as $case) {
        $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload($case['name'], $case['unit'], $case['type']))
            ->assertSessionHasNoErrors();
        $code = ModuleDefinition::codeFromName($case['name']);
        $definition = ModuleDefinition::query()->where('code', $code)->firstOrFail();
        $definitions[$code] = $definition;
        expect($definition->requirement_domain)->toBe($case['unit'] === 'development' ? 'engp' : 'pa')
            ->and($definition->requirement_key)->toBe($code)
            ->and($definition->plan_duration_years)->toBeNull()
            ->and($definition->requirement_metadata['configured_generic_flow'])->toBeTrue();

        if ($case['unit'] === 'conservation') {
            $workflow = app(ConservationReportWorkflowRegistry::class)->find($code);
            expect($workflow['activities'])->toBe([$case['name'].' Activity'])
                ->and($workflow['activity_documents'][$case['name'].' Activity'])->toBe(['Progress Report', 'Final Report'])
                ->and($workflow['periods'])->toContain('Future Period 2027')
                ->and(collect(app(ConservationReportWorkflowRegistry::class)->all())->pluck('key'))->toContain($code);

            $this->actingAs($conservationFocal)->get(route('conservation-reports.index', $code))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->component('ConservationReports/Index')
                    ->where('workflow.key', $code)
                    ->where('workflow.configured_generic_flow', true)
                    ->where('workflow.document_type_required', true)
                    ->where('workflow.period_required', true)
                    ->where('workflow.periods', fn ($periods): bool => collect($periods)->contains(fn ($period): bool => str_starts_with((string) $period, 'Current Period')) && collect($periods)->contains('Future Period 2027')));

            $period = collect($workflow['periods'])->first(fn (string $period): bool => str_starts_with($period, 'Current Period'));
            $validValues = [
                'protected_area_id' => $area->id,
                'target_office' => 'CENRO Mati',
                'activity_name' => $case['name'].' Activity',
                'document_type' => 'Progress Report',
                'reporting_period' => $period,
                'date_accomplished' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(),
            ];
            $missingDocument = $validValues;
            unset($missingDocument['document_type']);
            $this->actingAs($conservationFocal)->post(route('conservation-reports.store', $code), [
                ...$missingDocument,
                'mov' => UploadedFile::fake()->create('missing-document.pdf', 12, 'application/pdf'),
            ])->assertSessionHasErrors('document_type');
            expect(ConservationReportSubmission::query()->where('workflow_key', $code)->count())->toBe(0);

            $missingPeriod = $validValues;
            unset($missingPeriod['reporting_period']);
            $this->actingAs($conservationFocal)->post(route('conservation-reports.store', $code), [
                ...$missingPeriod,
                'mov' => UploadedFile::fake()->create('missing-period.pdf', 12, 'application/pdf'),
            ])->assertSessionHasErrors('reporting_period');
            expect(ConservationReportSubmission::query()->where('workflow_key', $code)->count())->toBe(0);

            $invalidDocument = [...$validValues, 'document_type' => 'Unlisted Report'];
            $this->actingAs($conservationFocal)->post(route('conservation-reports.store', $code), [
                ...$invalidDocument,
                'mov' => UploadedFile::fake()->create('invalid-document.pdf', 12, 'application/pdf'),
            ])->assertSessionHasErrors('document_type');
            expect(ConservationReportSubmission::query()->where('workflow_key', $code)->count())->toBe(0);

            $invalidPeriod = [...$validValues, 'reporting_period' => 'Unlisted Period'];
            $this->actingAs($conservationFocal)->post(route('conservation-reports.store', $code), [
                ...$invalidPeriod,
                'mov' => UploadedFile::fake()->create('invalid-period.pdf', 12, 'application/pdf'),
            ])->assertSessionHasErrors('reporting_period');
            expect(ConservationReportSubmission::query()->where('workflow_key', $code)->count())->toBe(0);

            $this->actingAs($conservationFocal)->post(route('conservation-reports.store', $code), [
                ...$validValues,
                'mov' => UploadedFile::fake()->create('dynamic-report.pdf', 12, 'application/pdf'),
            ])->assertSessionHasNoErrors();
            $record = ConservationReportSubmission::query()->where('workflow_key', $code)->firstOrFail();
            expect($record->document_type)->toBe('Progress Report')
                ->and($record->reporting_period)->toBe($period);

            $updateValues = $validValues;
            $missingUpdateDocument = $updateValues;
            unset($missingUpdateDocument['document_type']);
            $this->actingAs($conservationFocal)->put(route('conservation-reports.update', [$code, $record]), $missingUpdateDocument)
                ->assertSessionHasErrors('document_type');
            $missingUpdatePeriod = $updateValues;
            unset($missingUpdatePeriod['reporting_period']);
            $this->actingAs($conservationFocal)->put(route('conservation-reports.update', [$code, $record]), $missingUpdatePeriod)
                ->assertSessionHasErrors('reporting_period');
            expect($record->fresh()->document_type)->toBe('Progress Report')
                ->and($record->fresh()->reporting_period)->toBe($period);

            dynamicModuleRouteToPenro('conservation', $record->id, 'CENRO Mati', $conservationFocal);
            expect($record->fresh()->date_received_penro)->not->toBeNull();
        } else {
            $workflow = app(EngpReportWorkflowRegistry::class)->find($code);
            expect($workflow['activities'])->toBe([$case['name'].' Activity'])
                ->and($workflow['activity_documents'][$case['name'].' Activity'])->toBe(['Progress Report', 'Final Report'])
                ->and(app(EngpReportWorkflowRegistry::class)->periods($code, 2027))->not->toBeEmpty()
                ->and(collect(app(EngpReportWorkflowRegistry::class)->all())->pluck('key'))->toContain($code);

            $this->actingAs($developmentFocal)->get(route('engp-reports.index', $code))
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->component('Engp/Index')
                    ->where('workflowConfig.key', $code)
                    ->where('workflowConfig.configured_generic_flow', true)
                    ->where('periodsByYear.2027', fn ($periods): bool => collect($periods)->contains(fn (array $period): bool => $period['key'] === 'future_period_2027')));

            $this->actingAs($developmentFocal)->post(route('engp-reports.store', $code), [
                'office' => 'CENRO Baganga', 'section_name' => 'Dynamic Flow Test',
                'activity_name' => $case['name'].' Activity', 'document_type' => 'Progress Report',
                'reporting_year' => 2026, 'period_key' => 'current_period',
                'mov' => UploadedFile::fake()->createWithContent('dynamic-engp-source.pdf', "%PDF-1.7\nDynamic ENGP source MOV"),
            ])->assertSessionHasNoErrors();
            $record = EngpReportSubmission::query()->where('workflow_key', $code)->firstOrFail();
            dynamicModuleRouteToPenro('engp', $record->id, 'CENRO Baganga', $developmentFocal);
            expect($record->fresh()->date_received_penro)->not->toBeNull();
        }
    }

    $navigation = $this->actingAs($manager)->get(route('module-definitions.index'))->assertOk()->viewData('page')['props']['genericModuleNavigation'];
    foreach ($definitions as $key => $definition) {
        $href = $definition->requirement_domain === 'engp'
            ? route('engp-reports.index', $key, false)
            : route('conservation-reports.index', $key, false);
        expect(collect($navigation)->pluck('href'))->toContain($href);
    }

    $unrelated = User::factory()->create([
        'section' => 'ACCOUNTING', 'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'office_designated' => 'PENRO Davao Oriental',
    ]);
    $unrelated->givePermissionTo(Permission::findOrCreate('technical-reports.view', 'web'));
    $this->actingAs($unrelated)->get(route('engp-reports.index', 'qa_flow_development_regular'))->assertForbidden();

    $today = CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString();
    $tat = app(OfficeTatProcessedDocumentSourceService::class);
    $conservationDetails = $tat->forAssessment($conservationFocal, [
        'unit' => 'conservation', 'period_key' => '2026-H2', 'as_of_date' => $today,
        'rows' => [['participant_key' => 'pa:'.$area->id, 'name' => $area->name, 'office_binding' => 'CENRO Mati']],
    ]);
    $developmentDetails = $tat->forAssessment($developmentFocal, [
        'unit' => 'development', 'period_key' => '2026-H2', 'as_of_date' => $today,
        'rows' => [['participant_key' => 'office:cenro_baganga', 'name' => 'CENRO Baganga', 'office_binding' => 'CENRO Baganga']],
    ]);
    $keysIn = fn (array $rows): array => collect($rows)->flatMap(fn (array $row) => collect($row['obligation_details'])->pluck('source_identity.workflow_key'))->all();

    expect($keysIn($conservationDetails))->toContain('qa_flow_conservation_regular', 'qa_flow_conservation_plan')
        ->and($keysIn($developmentDetails))->toContain('qa_flow_development_regular', 'qa_flow_development_plan');

    config([
        'services.google_drive_archive.enabled' => true,
        'services.document_archive.driver' => 'fake',
        'services.document_archive.final_return_archive_enabled' => true,
    ]);
    app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());
    $archives = app(FinalDocumentArchiveService::class);
    foreach ($definitions as $key => $definition) {
        $record = $definition->requirement_domain === 'engp'
            ? EngpReportSubmission::query()->where('workflow_key', $key)->firstOrFail()
            : ConservationReportSubmission::query()->where('workflow_key', $key)->firstOrFail();
        $source = $definition->requirement_domain === 'engp' ? 'engp' : 'conservation';
        $unitAssignment = $source === 'engp' ? OrganizationalAccessService::DEVELOPMENT : OrganizationalAccessService::CONSERVATION;
        $penroFocal = dynamicModuleRouteToRegional($source, (int) $record->getKey(), $unitAssignment, $source === 'engp' ? $developmentFocal : $conservationFocal);
        $archives->recordReceipt($penroFocal, $source, (int) $record->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'));
        $result = $archives->createOrRetryManifest(
            $penroFocal, $source, (int) $record->getKey(), (string) Str::uuid(),
            [UploadedFile::fake()->createWithContent('dynamic-final-return.pdf', "%PDF-1.7\nFinal returned copy for {$key}")], true,
        );
        $manifest = FinalDocumentArchiveManifest::query()->with('files.archive')
            ->where('source_type', $source)->where('source_id', $record->getKey())->firstOrFail();
        $file = $manifest->files->firstOrFail();

        expect($result['status'])->toBe('archived')
            ->and($manifest->archive_status)->toBe('ARCHIVED')
            ->and($manifest->workflow_key)->toBe($key)
            ->and($manifest->files)->toHaveCount(1)
            ->and($file->file_status)->toBe('ARCHIVED')
            ->and($archives->authorizeManifestFileReader($penroFocal, $file)->getKey())->toBe($file->document_archive_id);

        $archiveUrl = route('final-archives.index', ['source' => $source, 'record' => $record->getKey()]);
        $this->actingAs($penroFocal)->get(route('final-archives.files.show', $file->getKey()).'?download=1')->assertOk();
        $this->actingAs($penroFocal)->get($archiveUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.workflow_key', $key)
                ->where('selected.latest_manifest.status', 'ARCHIVED'));
        $this->actingAs($penroFocal)->get(route('submission-tracking.index', [
            'view' => 'history', 'source' => $source, 'source_id' => $record->getKey(),
        ]))->assertRedirect($archiveUrl);

        $moduleId = (int) $definition->getKey();
        $this->actingAs($manager)->patch(route('module-definitions.status', $definition))->assertSessionHasNoErrors();
        expect($definition->fresh()->is_active)->toBeFalse();
        $this->actingAs($penroFocal)->get($archiveUrl)->assertOk();
        $this->actingAs($penroFocal)->get(route('final-archives.files.show', $file->getKey()).'?download=1')->assertOk();
        $this->actingAs($manager)->patch(route('module-definitions.status', $definition))->assertSessionHasNoErrors();
        $sourceCount = $source === 'engp'
            ? EngpReportSubmission::query()->where('workflow_key', $key)->count()
            : ConservationReportSubmission::query()->where('workflow_key', $key)->count();
        expect((int) $definition->fresh()->getKey())->toBe($moduleId)
            ->and($definition->fresh()->is_active)->toBeTrue()
            ->and($sourceCount)->toBe(1);
    }

    $legacyWorkflow = app(ConservationReportWorkflowRegistry::class)->find('homestay');
    $this->actingAs($conservationFocal)->get(route('conservation-reports.index', 'homestay'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workflow.document_type_required', false)
            ->where('workflow.period_required', false));
    $this->actingAs($conservationFocal)->post(route('conservation-reports.store', 'homestay'), [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => $legacyWorkflow['default_activity'],
        'date_conducted' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(),
        'date_conducted_ranges' => [[
            'from' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(),
            'to' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(),
        ]],
        'date_accomplished' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(),
        'mov' => UploadedFile::fake()->create('optional-native-report.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $legacyRecord = ConservationReportSubmission::query()->where('workflow_key', 'homestay')->firstOrFail();
    expect($legacyRecord->document_type)->toBeNull()
        ->and($legacyRecord->reporting_period)->toBeNull();

    $configuredDefinition = collect($definitions)->first(fn (ModuleDefinition $definition): bool => $definition->requirement_domain === 'pa');
    $configuredWorkflow = app(ConservationReportWorkflowRegistry::class)->find($configuredDefinition->requirement_key);
    $pamoOffice = OrganizationalOffice::query()->create([
        'code' => 'dynamic_pamo_'.$area->getKey(),
        'name' => 'PAMO Dynamic Flow Test',
        'office_type' => 'pamo',
        'is_active' => true,
    ]);
    $area->forceFill(['pamo' => $pamoOffice->name])->save();
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => $pamoOffice->id,
        'assignment_type' => 'pamo',
        'assigned_by' => $conservationFocal->id,
    ]);
    $pamo = dynamicModuleUser(OrganizationalAccessService::PAMO, $pamoOffice->name);
    $pamo->forceFill(['protected_area_id' => $area->id])->save();

    $pamoPeriod = collect($configuredWorkflow['periods'])->first(fn (string $period): bool => str_starts_with($period, 'Current Period'));
    $pamoValues = [
        'activity_name' => $configuredWorkflow['activities'][0],
        'document_type' => $configuredWorkflow['documents'][0],
        'reporting_period' => $pamoPeriod,
        'date_accomplished' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->toDateString(),
    ];
    $pamoSourceCount = ConservationReportSubmission::query()->where('workflow_key', $configuredDefinition->requirement_key)->count();
    $pamoToken = (string) Str::uuid();
    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.store', $configuredDefinition->requirement_key), [
        'start_idempotency_key' => $pamoToken,
        'mov' => UploadedFile::fake()->createWithContent('configured-pamo-draft.pdf', "%PDF-1.7\nConfigured PAMO draft source MOV"),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $pamoDraft = \App\Models\PamoPambDraft::query()->where('start_idempotency_key', $pamoToken)->firstOrFail();

    foreach (['document_type', 'reporting_period'] as $requiredField) {
        $incompleteValues = $pamoValues;
        unset($incompleteValues[$requiredField]);
        $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$configuredDefinition->requirement_key, $pamoDraft->draft_uuid]), [
            'lock_version' => $pamoDraft->fresh()->lock_version,
            ...$incompleteValues,
        ])->assertRedirect()->assertSessionHasErrors($requiredField);
        expect($pamoDraft->fresh()->status)->toBe('draft')
            ->and(ConservationReportSubmission::query()->where('workflow_key', $configuredDefinition->requirement_key)->count())->toBe($pamoSourceCount);
    }

    $this->actingAs($pamo)->post(route('conservation-reports.pamo-drafts.forward', [$configuredDefinition->requirement_key, $pamoDraft->draft_uuid]), [
        'lock_version' => $pamoDraft->fresh()->lock_version,
        ...$pamoValues,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $pamoSource = ConservationReportSubmission::query()->findOrFail($pamoDraft->fresh()->conservation_report_submission_id);
    expect($pamoSource->document_type)->toBe($pamoValues['document_type'])
        ->and($pamoSource->reporting_period)->toBe($pamoPeriod);
});

test('inactive arbitrary Conservation modules block first writes and overrides while allowing an existing route to continue', function (): void {
    Storage::fake('local');
    $manager = dynamicModuleManager();
    $focal = dynamicModuleUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = dynamicModuleUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $area = ProtectedArea::create([
        'name' => 'Dynamic Lifecycle Existing PA', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
    ]);
    $name = 'QA Inactive Arbitrary Conservation Module';
    $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload(
        $name, 'conservation', ModuleDefinition::TYPE_REGULAR_TARGET,
    ))->assertSessionHasNoErrors();
    $definition = ModuleDefinition::query()->where('code', 'qa_inactive_arbitrary_conservation_module')->firstOrFail();
    $workflow = app(ConservationReportWorkflowRegistry::class)->find($definition->requirement_key);
    $period = collect($workflow['periods'])->first(fn (string $label): bool => str_starts_with($label, 'Current Period'));
    $this->actingAs($focal)->post(route('conservation-reports.store', $definition->requirement_key), [
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => $name.' Activity', 'document_type' => 'Progress Report', 'reporting_period' => $period,
        'date_accomplished' => CarbonImmutable::now('Asia/Manila')->toDateString(),
        'mov' => UploadedFile::fake()->create('dynamic-lifecycle.pdf', 12, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $existing = ConservationReportSubmission::query()->where('workflow_key', $definition->requirement_key)->firstOrFail();
    $tracking = app(SubmissionTrackingService::class);
    $this->actingAs($focal);
    $tracking->transition('conservation', (int) $existing->getKey(), 'forward_to_cenro_chief', null, $focal->id);
    $firstEvent = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $existing->getKey())->firstOrFail();

    $unrouted = ConservationReportSubmission::query()->create([
        'workflow_key' => $definition->requirement_key, 'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati', 'activity_name' => $name.' Activity', 'document_type' => 'Progress Report',
        'reporting_period' => 'Future Period 2027', 'date_accomplished' => '2027-05-01',
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    $this->actingAs($manager)->patch(route('module-definitions.status', $definition))->assertSessionHasNoErrors();

    $newArea = ProtectedArea::create([
        'name' => 'Dynamic Lifecycle Rejected PA', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $newArea->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising', 'assigned_by' => $focal->id,
    ]);
    $this->actingAs($focal)->post(route('conservation-reports.store', $definition->requirement_key), [
        'protected_area_id' => $newArea->id, 'target_office' => 'CENRO Mati',
        'activity_name' => $name.' Activity', 'document_type' => 'Progress Report', 'reporting_period' => $period,
        'date_accomplished' => CarbonImmutable::now('Asia/Manila')->toDateString(),
        'mov' => UploadedFile::fake()->create('blocked-dynamic-lifecycle.pdf', 12, 'application/pdf'),
    ])->assertSessionHasErrors('workflow');
    expect(ConservationReportSubmission::query()->where('workflow_key', $definition->requirement_key)->count())->toBe(2)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $unrouted->getKey())->count())->toBe(0);

    $admin = User::factory()->create(['unit_assignment' => OrganizationalAccessService::CONSERVATION, 'section' => 'CDS']);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.admin-override', 'web'));
    $override = app(AdminRoutingOverrideService::class)->available('conservation', (int) $unrouted->getKey(), $admin);
    $action = collect($override['actions'])->first();
    expect($override['available'])->toBeTrue()->and($action)->not->toBeNull();
    expect(fn () => app(DocumentRoutingTransitionService::class)->transitionAsOverride(
        $unrouted, 'conservation', $action['key'], $admin,
        ['override_for_category' => $action['accountable_category_key'], 'override_for_office' => $action['accountable_office']],
        'Dynamic inactive-module override guard fixture',
    ))->toThrow(ValidationException::class, 'This report module is inactive and cannot start a new routing flow.');
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $unrouted->getKey())->count())->toBe(0);

    $this->actingAs($chief);
    $tracking->transition('conservation', (int) $existing->getKey(), 'receive_at_cenro_chief', null, $chief->id);
    $continued = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $existing->getKey())->orderBy('id')->latest('id')->firstOrFail();
    foreach (['route_setting_version', 'route_setting_version_id', 'route_snapshot_id', 'route_graph_version'] as $field) {
        expect(data_get($continued->metadata, $field))->toBe(data_get($firstEvent->metadata, $field));
    }

    $this->actingAs($manager)->patch(route('module-definitions.status', $definition))->assertSessionHasNoErrors();
    expect((int) $definition->fresh()->getKey())->toBe((int) $definition->getKey())
        ->and($definition->fresh()->is_active)->toBeTrue()
        ->and(ConservationReportSubmission::query()->where('workflow_key', $definition->requirement_key)->count())->toBe(2);
});

test('inactive Development modules reject new submissions and first routing but keep an existing route moving', function (): void {
    $manager = dynamicModuleManager();
    $focal = dynamicModuleUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $chief = dynamicModuleUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga');
    $otherFocal = dynamicModuleUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $name = 'QA Inactive Development Module';
    $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload(
        $name, 'development', ModuleDefinition::TYPE_REGULAR_TARGET,
        ['document_types' => 'Progress Report'],
    ))->assertSessionHasNoErrors();
    $definition = ModuleDefinition::query()->where('code', 'qa_inactive_development_module')->firstOrFail();
    $workflow = app(EngpReportWorkflowRegistry::class)->find((string) $definition->code);

    $this->actingAs($focal)->post(route('engp-reports.store', $definition->code), [
        'office' => 'CENRO Baganga', 'reporting_year' => 2026, 'period_key' => 'current_period',
        'activity_name' => 'Unexpected activity', 'document_type' => 'Progress Report',
    ])->assertSessionHasErrors('activity_name');
    $this->actingAs($focal)->post(route('engp-reports.store', $definition->code), [
        'office' => 'CENRO Baganga', 'reporting_year' => 2026, 'period_key' => 'current_period',
        'activity_name' => $name.' Activity', 'document_type' => 'Final Report',
    ])->assertSessionHasErrors('document_type');
    $this->actingAs($focal)->post(route('engp-reports.store', $definition->code), [
        'office' => 'CENRO Baganga', 'reporting_year' => 2026, 'period_key' => 'current_period',
        'activity_name' => $name.' Activity', 'document_type' => 'Progress Report',
    ])->assertSessionHasNoErrors();
    $existing = EngpReportSubmission::query()->where('workflow_key', $definition->code)->firstOrFail();
    $tracking = app(SubmissionTrackingService::class);
    $this->actingAs($focal);
    $tracking->transition('engp', (int) $existing->getKey(), 'forward_to_cenro_chief', null, $focal->id);

    $unrouted = EngpReportSubmission::query()->create([
        'workflow_key' => $definition->code, 'office' => 'CENRO Mati', 'section_name' => 'Inactive module fixture',
        'activity_name' => $name.' Activity', 'document_type' => 'Progress Report',
        'reporting_year' => 2026, 'period_key' => 'current_period', 'period_label' => 'Current Period',
        'deadline_submission' => app(EngpReportWorkflowRegistry::class)->deadline((string) $definition->code, 2026, 'current_period', false),
        'created_by' => $focal->id, 'updated_by' => $focal->id,
    ]);
    $this->actingAs($manager)->patch(route('module-definitions.status', $definition))->assertSessionHasNoErrors();

    $this->actingAs($focal)->post(route('engp-reports.store', $definition->code), [
        'office' => 'CENRO Baganga', 'reporting_year' => 2027, 'period_key' => 'future_period_2027',
        'activity_name' => $name.' Activity', 'document_type' => 'Progress Report',
    ])->assertSessionHasErrors('workflow');
    $routingErrors = [];
    try {
        app(DocumentRoutingTransitionService::class)->transition($unrouted, 'engp', 'forward_to_cenro_chief', $otherFocal->id);
    } catch (ValidationException $exception) {
        $routingErrors = $exception->errors();
    }
    expect($routingErrors['workflow'][0] ?? null)->toBe('This report module is inactive and cannot start a new routing flow.');

    $this->actingAs($chief);
    $tracking->transition('engp', (int) $existing->getKey(), 'receive_at_cenro_chief', null, $chief->id);
    expect(DocumentRoutingEvent::query()->where('source_type', 'engp')->where('source_id', $existing->getKey())->count())->toBe(2)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'engp')->where('source_id', $unrouted->getKey())->count())->toBe(0);
});

test('dynamic Development reminders use only configured offices and resolve through the existing fake mail delivery', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 08:00:00', 'Asia/Manila'));
    Mail::fake();
    config([
        'compliance_alerts.enabled' => true,
        'compliance_alerts.scheduled_engp_obligations' => true,
        'mail.default' => 'log',
        'mail.from.address' => 'alerts@example.test',
    ]);
    $manager = dynamicModuleManager();
    $base = [
        'reporting_frequency' => 'custom',
        'period_labels' => 'Due Today | 2026-10-01 | 2026-10-27',
        'deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS,
        'default_deadline_days' => 1,
    ];
    $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload(
        'QA Alert Configured Development Module', 'development', ModuleDefinition::TYPE_PLAN,
        [...$base, 'reminder_offices' => ['CENRO Baganga']],
    ))->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload(
        'QA Alert Unscheduled Development Module', 'development', ModuleDefinition::TYPE_REGULAR_TARGET,
        [...$base, 'reminder_offices' => []],
    ))->assertSessionHasNoErrors();
    ComplianceAlertRecipient::query()->create([
        'target_office' => 'CENRO Baganga', 'target_office_key' => 'cenro_baganga',
        'recipient_email' => 'dynamic-flow-alert@example.test', 'is_active' => true,
    ]);
    \App\Models\ComplianceAlertSetting::query()->create([
        'alerts_enabled' => true, 'automatic_send_enabled' => true,
        'send_time' => '08:00', 'timezone' => 'Asia/Manila',
    ]);

    $service = app(OverdueReportService::class);
    $configuredKey = 'qa_alert_configured_development_module';
    $unscheduledKey = 'qa_alert_unscheduled_development_module';
    $dueToday = $service->dueTodayReports(CarbonImmutable::now('Asia/Manila'))
        ->where('workflowKey', $configuredKey)->values();
    $unselected = $service->dueTodayReports(CarbonImmutable::now('Asia/Manila'))
        ->where('workflowKey', $unscheduledKey)->values();
    $delivery = app(ComplianceAlertDeliveryService::class);
    $plan = $delivery->deliveryPlan($dueToday, ComplianceNotificationRun::ALERT_DUE_TODAY);

    expect($dueToday)->toHaveCount(1)
        ->and($dueToday->first()->deadline)->toBe('2026-10-28')
        ->and($unselected)->toBeEmpty()
        ->and($plan['deliveries'])->toHaveCount(1)
        ->and($plan['deliveries']->first()['recipient']->email)->toBe('dynamic-flow-alert@example.test');

    $delivery->sendAutomatic();
    Mail::assertSent(OverdueComplianceMemorandum::class, 1);
    $run = ComplianceNotificationRun::query()
        ->where('alert_type', ComplianceNotificationRun::ALERT_DUE_TODAY)
        ->where('status', ComplianceNotificationRun::STATUS_SENT)->firstOrFail();
    expect($run->reports()->firstOrFail()->snapshot['workflow_key'])->toBe($configuredKey)
        ->and($run->reports()->firstOrFail()->snapshot['target_office'])->toBe('CENRO Baganga');
});

test('dynamic custom periods reject impossible calendar dates', function (): void {
    $manager = dynamicModuleManager();

    $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload(
        'Invalid Dynamic Period', 'conservation', ModuleDefinition::TYPE_PLAN,
        ['period_labels' => 'Impossible Date | 2027-02-30 | 2027-12-31']
    ))->assertSessionHasErrors('period_labels');

    expect(ModuleDefinition::query()->where('code', 'invalid_dynamic_period')->exists())->toBeFalse();
});

test('legacy plan duration is retained on edit and dynamic policy is locked after reports exist', function (): void {
    $manager = dynamicModuleManager();
    $this->actingAs($manager)->post(route('module-definitions.store'), dynamicModulePayload(
        'Policy Locked Dynamic Module', 'conservation', ModuleDefinition::TYPE_PLAN,
        ['reporting_frequency' => 'annual', 'period_labels' => '']
    ))->assertSessionHasNoErrors();
    $definition = ModuleDefinition::query()->where('code', 'policy_locked_dynamic_module')->firstOrFail();
    ConservationReportSubmission::query()->create([
        'workflow_key' => $definition->requirement_key,
        'activity_name' => 'Policy Locked Dynamic Module Activity',
        'document_type' => 'Progress Report', 'reporting_period' => '2026',
        'date_accomplished' => '2026-10-01',
    ]);

    $samePolicy = dynamicModulePayload('Policy Locked Dynamic Module Renamed', 'conservation', ModuleDefinition::TYPE_PLAN, [
        'reporting_frequency' => 'annual', 'period_labels' => '',
        'activity_name' => 'Policy Locked Dynamic Module Activity',
    ]);
    $this->actingAs($manager)->put(route('module-definitions.update', $definition), $samePolicy)->assertSessionHasNoErrors();
    expect($definition->fresh()->code)->toBe('policy_locked_dynamic_module')
        ->and($definition->fresh()->name)->toBe('Policy Locked Dynamic Module Renamed');

    $changedPolicy = [...$samePolicy, 'reporting_frequency' => 'quarterly'];
    $this->actingAs($manager)->put(route('module-definitions.update', $definition), $changedPolicy)->assertSessionHasErrors('reporting_frequency');
    expect($definition->fresh()->reporting_frequency)->toBe('annual');

    $legacy = ModuleDefinition::query()->create([
        'name' => 'Legacy Duration Plan', 'code' => 'legacy_duration_plan',
        'program_area' => ProgramArea::PROTECTED_AREA_MANAGEMENT_AND_DEVELOPMENT->value,
        'implementation_type' => ModuleDefinition::IMPLEMENTATION_GENERIC, 'module_type' => ModuleDefinition::TYPE_PLAN,
        'reporting_frequency' => 'annual', 'plan_duration_years' => 7,
        'deadline_mode' => ModuleDefinition::DEADLINE_NONE, 'is_active' => true,
    ]);
    $legacyEdit = dynamicModulePayload('Legacy Duration Plan Renamed', 'conservation', ModuleDefinition::TYPE_PLAN, [
        'reporting_frequency' => 'annual', 'deadline_mode' => ModuleDefinition::DEADLINE_NONE,
        'default_deadline_days' => null, 'period_labels' => '',
    ]);
    $this->actingAs($manager)->put(route('module-definitions.update', $legacy), $legacyEdit)->assertSessionHasNoErrors();
    expect($legacy->fresh()->plan_duration_years)->toBe(7);
});
