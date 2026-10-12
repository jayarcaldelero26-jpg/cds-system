<?php

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\OfficeTatComplianceAssessmentService;
use App\Services\OfficeTatObligationEvidenceSetupService;
use App\Models\OfficeTatApprovalEvidence;
use App\Models\OfficeTatApplicabilityVersion;
use App\Models\OfficeTatObligationScheduleVersion;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Compliance\ComplianceAlertDeliveryService;
use Carbon\CarbonImmutable;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    app()->instance(GoogleDriveArchiveGateway::class, Mockery::mock(GoogleDriveArchiveGateway::class));
    app()->instance(ComplianceAlertDeliveryService::class, Mockery::mock(ComplianceAlertDeliveryService::class));
    $this->seed(ModuleDefinitionSeeder::class);

    foreach (['CENRO Baganga', 'CENRO Manay', 'CENRO Lupon', 'CENRO Mati'] as $index => $name) {
        $office = OrganizationalOffice::query()->where('name', $name)->first();
        if (! $office) $office = OrganizationalOffice::query()->create([
            'code' => 'tat-assessment-cenro-'.$index, 'name' => $name, 'office_type' => 'cenro', 'is_active' => true,
        ]);
        else $office->forceFill(['office_type' => 'cenro', 'is_active' => true])->save();
    }
});

function officeTatApprovalEvidence(User $actor, string $kind, array $identity): string
{
    $setup = app(OfficeTatObligationEvidenceSetupService::class);
    $scope = $setup->uploadScope($actor, $kind, $identity);
    $file = UploadedFile::fake()->create('approved-source.pdf', 4, 'application/pdf');
    return $setup->uploadApprovalEvidence($actor, $file, $scope)->public_id;
}

function officeTatApprovalFields(User $actor, string $kind, array $identity, string $authority = 'Protected Area Board'): array
{
    return [
        'approving_authority' => $authority,
        'approval_reference' => 'MEMO-2026-042',
        'rationale' => 'Approved by the responsible authority for the stated period.',
        'evidence_reference' => officeTatApprovalEvidence($actor, $kind, $identity),
    ];
}

function officeTatSetApplicability(User $actor, ProtectedArea $area, string $status, string $from, string $workflow = 'regular_pamb', string $activity = 'Regular PAMB', string $document = 'Minutes', ?string $to = null, int $expectedVersion = 0): OfficeTatApplicabilityVersion
{
    $identity = ['protected_area_id' => $area->id, 'workflow_key' => $workflow, 'activity_name' => $activity, 'document_type' => $document];
    $approval = $status === 'unknown' ? [] : officeTatApprovalFields($actor, 'applicability', $identity);
    return app(OfficeTatObligationEvidenceSetupService::class)->saveApplicability($actor, [
        ...$identity, 'status' => $status, 'effective_from' => $from, 'effective_to' => $to, 'expected_version' => $expectedVersion,
        'approving_authority' => $approval['approving_authority'] ?? '', 'approval_reference' => $approval['approval_reference'] ?? '',
        'rationale' => $approval['rationale'] ?? 'Applicability has not yet been established for this exact activity/document pair.',
        'evidence_reference' => $approval['evidence_reference'] ?? '',
    ]);
}

function officeTatSetSchedule(User $actor, ProtectedArea $area, array $occurrences, string $kind = 'event', string $period = 'quarter_1', int $year = 2027, int $expectedVersion = 0, string $workflow = 'regular_pamb', string $activity = 'Regular PAMB', string $document = 'Minutes'): OfficeTatObligationScheduleVersion
{
    $identity = ['protected_area_id' => $area->id, 'workflow_key' => $workflow, 'activity_name' => $activity, 'document_type' => $document, 'reporting_year' => $year, 'period_key' => $period];
    $approval = officeTatApprovalFields($actor, 'schedule', $identity);
    return app(OfficeTatObligationEvidenceSetupService::class)->saveSchedule($actor, [
        ...$identity, 'schedule_kind' => $kind, 'occurrences' => $occurrences, 'inventory_complete' => true,
        ...$approval, 'expected_version' => $expectedVersion,
    ]);
}

function complianceAssessmentArea(string $name, string $status, string $pamo): ProtectedArea
{
    return ProtectedArea::query()->create([
        'name' => $name, 'short_name' => strtoupper(substr($name, 0, 5)),
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => $status,
        'pamo' => $pamo,
    ]);
}

test('assessment uses six stable PA identities and four ENGP-only CENRO participants', function (): void {
    $areas = collect([
        ['Mati Protected Landscape (MPL)', 'Active', 'PAMO-MPL'],
        ['Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 'Active', 'PAMO-MHRWS'],
        ['Pujada Bay Protected Landscape and Seascape (PBPLS)', 'Active', 'PAMO-PBPLS'],
        ['Aliwagwag Protected Landscape (APL)', 'Proposed', 'APL-PAMO'],
        ['Baganga Mangrove Swamp Forest Reserve', 'Proposed', 'BMSFR-PAMO'],
        ['Baganga Proteceted Landscape', 'Proposed', 'BPL-PAMO'],
    ])->map(fn (array $row) => complianceAssessmentArea(...$row));

    ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $areas[3]->id,
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1', 'date_conducted' => '2027-03-10',
        'date_accomplished' => '2027-03-10', 'date_received_penro' => '2027-03-12',
    ]);

    $result = app(OfficeTatComplianceAssessmentService::class)->assessments('2027', '2027-12-31');
    $conservation = $result['conservation'];
    $development = $result['development'];

    expect($conservation['expected_roster_count'])->toBe(6)
        ->and($conservation['roster_count'])->toBe(6)
        ->and(collect($conservation['rows'])->pluck('participant_key')->sort()->values()->all())->toBe(['pa:1', 'pa:2', 'pa:3', 'pa:4', 'pa:5', 'pa:6'])
        ->and(collect($conservation['rows'])->firstWhere('name', 'Aliwagwag Protected Landscape (APL)')['legal_status'])->toBe('Proposed')
        ->and(collect($conservation['rows'])->every(fn (array $row): bool => $row['office_binding'] === null))->toBeTrue()
        ->and($conservation['coverage_complete'])->toBeFalse()
        ->and($conservation['tc_percent'])->toBeNull()
        ->and($development['expected_roster_count'])->toBe(4)
        ->and($development['roster_count'])->toBe(4)
        ->and(collect($development['rows'])->pluck('name')->sort()->values()->all())->toBe(['CENRO Baganga', 'CENRO Lupon', 'CENRO Manay', 'CENRO Mati'])
        ->and(collect($development['rows'])->every(fn (array $row): bool => $row['due_count'] > 0 && $row['submitted_count'] === 0))->toBeTrue();
});

test('full ENGP duty inventory crosses a page-sized boundary and each source identity counts once', function (): void {
    $year = 2027;
    $office = 'CENRO Baganga';
    $requirements = app(App\Services\Reports\ReportRequirementRegistry::class);
    $registry = app(EngpReportWorkflowRegistry::class);
    $created = 0;
    $submittedWorkflows = [];

    foreach ($registry->all() as $workflow) {
        if (! in_array($office, $workflow['offices'] ?? [], true)) continue;
        foreach ($registry->periods($workflow['key'], $year) as $period) {
            $start = $requirements->periodStartDate('engp', $workflow['key'], $year, $period['key']);
            $deadline = $registry->deadline($workflow['key'], $year, $period['key']);
            if (! $start || ! $deadline || $deadline < $year.'-01-01' || $deadline > $year.'-12-31') continue;

            EngpReportSubmission::query()->create([
                'workflow_key' => $workflow['key'], 'office' => $office,
                'activity_name' => $workflow['activity'], 'document_type' => $workflow['document'],
                'reporting_year' => $year, 'period_key' => $period['key'], 'period_label' => $period['label'],
                'deadline_submission' => $deadline, 'date_received_penro' => $start,
            ]);
            $submittedWorkflows[$workflow['key']] = true;
            $created++;
            if ($created === 125) break 2;
        }
    }

    expect($created)->toBe(125);
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    $service = app(OfficeTatComplianceAssessmentService::class);
    foreach (array_keys($submittedWorkflows) as $workflowKey) {
        $service->saveTechnicalClassification($actor, 'engp', $workflowKey, false, [
            ...officeTatApprovalFields($actor, 'classification', ['domain' => 'engp', 'workflow_key' => $workflowKey]),
            'effective_from' => '2027-01-01', 'effective_to' => null, 'expected_version' => 0,
        ]);
    }
    $development = $service->assessments('2027', '2027-12-31')['development'];
    $baganga = collect($development['rows'])->firstWhere('name', $office);

    expect($development['coverage_complete'])->toBeTrue()
        ->and($baganga['score_status'])->toBe('Scored')
        ->and($baganga['submitted_count'])->toBe(125)
        ->and($baganga['on_time_count'])->toBe(125)
        ->and($baganga['volume_count'])->toBe(125)
        ->and(collect($baganga['obligation_details'])->where('source_rows_matched', 1)->count())->toBe(125);
});

test('Conservation duplicate rows use one earliest live receipt and source-specific PAMB dates', function (): void {
    $area = complianceAssessmentArea('PAMB Source Date Test PA', 'Proposed', 'Legacy PAMO label');
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    expect(app(OfficeTatObligationEvidenceSetupService::class)->schemaReady())->toBeTrue();
    $service = app(OfficeTatComplianceAssessmentService::class);
    try {
        officeTatSetApplicability($actor, $area, 'applicable', '2027-01-01');
    } catch (\Throwable $exception) {
        throw new \RuntimeException('Applicability setup failed: '.get_class($exception).' '.$exception->getMessage(), 0, $exception);
    }

    foreach (['2027-03-12', '2027-03-15'] as $received) {
        ConservationReportSubmission::query()->create([
            'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id,
            'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 1', 'date_conducted' => '2027-03-10',
            'date_received_penro' => $received,
        ]);
    }

    $source = ConservationReportSubmission::query()->first();
    expect(collect(app(App\Services\Reports\ReportRequirementRegistry::class)->periods('pa', 'regular_pamb', 2027))->pluck('key')->all())->toContain('quarter_1');
    expect(collect(app(OfficeTatObligationEvidenceSetupService::class)->catalogue())->contains(fn (array $pair): bool => $pair['workflow_key'] === 'regular_pamb' && $pair['activity_name'] === 'Regular PAMB' && $pair['document_type'] === 'Minutes'))->toBeTrue();
    try {
        officeTatSetSchedule($actor, $area, [[
            'occurrence_key' => 'regular-pamb-2027-03-10', 'start_date' => '2027-03-10',
            'deadline' => (string) $source->deadline_submission,
        ]]);
    } catch (\Throwable $exception) {
        throw new \RuntimeException('Schedule setup failed: '.get_class($exception).' '.$exception->getMessage().' status='.(method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 'n/a').' at '.$exception->getFile().':'.$exception->getLine(), 0, $exception);
    }

    $assessment = $service->assessments('2027-H1', '2027-06-30')['conservation'];
    $row = collect($assessment['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    $detail = collect($row['obligation_details'])->first(fn (array $item): bool => str_contains($item['label'], 'Regular PAMB Meetings') && str_ends_with($item['label'], 'Minutes'));

    expect($detail)->not->toBeNull()
        ->and($detail['start_date'])->toBe('2027-03-10')
        ->and($detail['received'])->toBe('2027-03-12')
        ->and($detail['source_rows_matched'])->toBe(2)
        ->and($detail['day_convention'])->toContain('PAMB working days')
        ->and($assessment['tc_percent'])->toBeNull();
});

test('tie approval requires configure permission and rechecks its live fingerprint', function (): void {
    $actor = User::factory()->create(['unit_assignment' => 'development', 'section' => 'CDS']);
    $service = app(OfficeTatComplianceAssessmentService::class);
    $assessment = $service->assessments('2027', '2027-12-31')['development'];
    expect($assessment['coverage_complete'])->toBeTrue();
    $group = collect($assessment['tie_groups'])->first(fn (array $candidate): bool => $candidate['resolvable']);
    expect($group)->not->toBeNull();
    $participants = $group['participant_keys'];
    $order = array_reverse($participants);

    try {
        $service->saveTieDecision($actor, 'development', '2027', $group['fingerprint'], $participants, $order, 'Reviewer approved ordering', '2027-12-31');
        self::fail('An unprivileged user must not record a tie decision.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }

    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    try {
        $service->saveTieDecision($actor, 'development', '2027', str_repeat('0', 64), $participants, $order, 'Reviewer approved ordering', '2027-12-31');
        self::fail('A stale metric fingerprint must not be accepted.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }

    $forgedParticipants = [...array_slice($participants, 0, -1), 'cenro:999'];
    try {
        $service->saveTieDecision($actor, 'development', '2027', $group['fingerprint'], $forgedParticipants, array_reverse($forgedParticipants), 'Forged participant identity', '2027-12-31');
        self::fail('A decision must contain the exact live tied participants.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }

    try {
        $service->saveTieDecision($actor, 'development', '2027', $group['fingerprint'], $participants, array_fill(0, count($participants), $participants[0]), 'Duplicate positions', '2027-12-31');
        self::fail('Tie positions must be complete and unique.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('order');
    }

    $decision = $service->saveTieDecision($actor, 'development', '2027', $group['fingerprint'], $participants, $order, 'Reviewer approved ordering', '2027-12-31');
    expect($decision->participant_keys)->toBe($participants)
        ->and($decision->ordered_participant_keys)->toBe($order)
        ->and($decision->approved_by)->toBe($actor->id)
        ->and($decision->approved_at)->not->toBeNull();

    $resolved = $service->assessments('2027', '2027-12-31')['development'];
    expect(collect($resolved['rows'])->sortBy('rank')->pluck('participant_key')->take(count($order))->values()->all())->toBe($order)
        ->and(collect($resolved['rows'])->pluck('rank')->sort()->values()->all())->toBe([1, 2, 3, 4]);

    $oldFingerprint = $group['fingerprint'];
    $service->saveTechnicalClassification($actor, 'engp', 'cbep', true, [
        ...officeTatApprovalFields($actor, 'classification', ['domain' => 'engp', 'workflow_key' => 'cbep']),
        'effective_from' => '2027-01-01', 'effective_to' => null, 'expected_version' => 0,
    ]);
    $changed = $service->assessments('2027', '2027-12-31')['development'];
    $changedGroup = collect($changed['tie_groups'])->first(fn (array $candidate): bool => $candidate['resolvable']);
    expect($changedGroup)->not->toBeNull()->and($changedGroup['fingerprint'])->not->toBe($oldFingerprint);
    try {
        $service->saveTieDecision($actor, 'development', '2027', $oldFingerprint, $participants, $order, 'Stale after evidence change', '2027-12-31');
        self::fail('A tie decision must not survive a classification evidence version change.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }
});

test('a configure-capable local viewer cannot read cross-unit assessments without global Office TAT access', function (): void {
    $view = Permission::findOrCreate('office-tat.view', 'web');
    $configure = Permission::findOrCreate('office-tat.configure', 'web');
    $viewer = User::factory()->create([
        'unit_assignment' => null, 'section' => OrganizationalAccessService::CENRO_FOCAL,
        'office_designated' => 'CENRO Baganga',
    ]);
    $viewer->givePermissionTo([$view, $configure]);

    $this->actingAs($viewer)->get('/admin/office-tat?category=CENRO&processing_period=2026-H2&as_of_date=2026-10-11')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/OfficeTat/Index')
        ->where('globalAssessmentAccess', false)
        ->where('complianceAssessments', null));

    $global = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $global->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $global->givePermissionTo([$view, $configure]);
    $this->actingAs($global)->get('/admin/office-tat?category=CENRO&processing_period=2026-H2&as_of_date=2026-10-11')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/OfficeTat/Index')
        ->where('globalAssessmentAccess', true)
        ->has('complianceAssessments.conservation')
        ->has('complianceAssessments.development'));
});

test('Office TAT route returns a roster whose selected unit and participant identities agree', function (): void {
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.view', 'web'));
    $area = complianceAssessmentArea('Unit Response Test PA', 'Active', 'PAMO Unit Response');

    $this->actingAs($actor)
        ->get('/admin/office-tat?category=CENRO&unit=conservation&processing_period=2026-H2&as_of_date=2026-10-11')
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('selectedUnit', 'conservation')
            ->where('filters.unit', 'conservation')
            ->where('recordedAssessment.unit', 'conservation')
            ->where('recordedAssessment.rows.0.participant_key', 'pa:'.$area->id));

    $this->actingAs($actor)
        ->get('/admin/office-tat?category=CENRO&unit=development&processing_period=2026-H2&as_of_date=2026-10-11')
        ->assertOk()->assertInertia(fn ($page) => $page
            ->where('selectedUnit', 'development')
            ->where('filters.unit', 'development')
            ->where('recordedAssessment.unit', 'development')
            ->where('recordedAssessment.rows.0.participant_key', fn ($key) => str_starts_with($key, 'cenro:')));
});

test('Conservation applicability and highly technical workflow classifications remain separate and versioned', function (): void {
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    $area = complianceAssessmentArea('Assessment Scope Test PA', 'Proposed', 'Legacy PAMO label');

    $scope = app(OfficeTatComplianceAssessmentService::class)->saveScope($actor, $area->id, ['regular_pamb'], true);
    expect($scope->version)->toBe(1)
        ->and($scope->workflow_keys)->toBe(['regular_pamb'])
        ->and($scope->coverage_complete)->toBeTrue();

    $service = app(OfficeTatComplianceAssessmentService::class);
    $conservationClass = $service->saveTechnicalClassification($actor, 'conservation', 'regular_pamb', true, [
        ...officeTatApprovalFields($actor, 'classification', ['domain' => 'conservation', 'workflow_key' => 'regular_pamb']),
        'effective_from' => '2027-01-01', 'effective_to' => null, 'expected_version' => 0,
    ]);
    $engpClass = $service->saveTechnicalClassification($actor, 'engp', 'cbep', false, [
        ...officeTatApprovalFields($actor, 'classification', ['domain' => 'engp', 'workflow_key' => 'cbep'], 'Conservation and Development Board'),
        'effective_from' => '2027-01-01', 'effective_to' => null, 'expected_version' => 0,
    ]);
    expect($conservationClass->version)->toBe(1)
        ->and($conservationClass->domain)->toBe('conservation')
        ->and($engpClass->version)->toBe(1)
        ->and($engpClass->domain)->toBe('engp');

    $assessment = $service->assessments('2027-H1', '2027-06-30');
    $paRow = collect($assessment['conservation']['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    $catalogue = collect($assessment['classification_catalogue']);
    expect($paRow['scope_workflow_keys'])->toBe(['regular_pamb'])
        ->and($assessment['conservation']['coverage_complete'])->toBeFalse()
        ->and($assessment['conservation']['tc_percent'])->toBeNull()
        ->and($catalogue->first(fn (array $item): bool => $item['domain'] === 'conservation' && $item['workflow_key'] === 'regular_pamb')['version'])->toBe(1)
        ->and($catalogue->first(fn (array $item): bool => $item['domain'] === 'engp' && $item['workflow_key'] === 'cbep')['version'])->toBe(1);
});

test('legacy coverage checkbox cannot override unknown applicability or missing schedules', function (): void {
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    $area = complianceAssessmentArea('Checkbox Guard Test PA', 'Proposed', 'PAMO label');
    $scope = app(OfficeTatComplianceAssessmentService::class)->saveScope($actor, $area->id, ['regular_pamb'], true);

    $conservation = app(OfficeTatComplianceAssessmentService::class)->assessments('2027-H1', '2027-06-30')['conservation'];
    $row = collect($conservation['rows'])->firstWhere('participant_key', 'pa:'.$area->id);

    expect($scope->coverage_complete)->toBeTrue()
        ->and($row['scope_configured_complete'])->toBeTrue()
        ->and($conservation['coverage_complete'])->toBeFalse()
        ->and($conservation['tc_percent'])->toBeNull()
        ->and(collect($conservation['coverage_reasons'])->some(fn (string $reason): bool => str_contains($reason, 'Unknown')))->toBeTrue();
});

test('applicability uses effective bounds and explicit zero meeting inventory never manufactures a duty', function (): void {
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    $area = complianceAssessmentArea('Effective Bounds Test PA', 'Proposed', 'PAMO label');

    $first = officeTatSetApplicability($actor, $area, 'not_applicable', '2027-01-01', 'homestay', 'Training on Homestay Program', 'Progress Report', '2027-06-30');
    $second = officeTatSetApplicability($actor, $area, 'applicable', '2027-07-01', 'homestay', 'Training on Homestay Program', 'Progress Report', expectedVersion: 1);
    expect($first->version)->toBe(1)->and($second->version)->toBe(2);
    officeTatSetApplicability($actor, $area, 'applicable', '2027-01-01');

    officeTatSetSchedule($actor, $area, [], 'event', 'quarter_1', 2027, 0, 'regular_pamb');
    $q1 = app(OfficeTatComplianceAssessmentService::class)->assessments('2027-H1', '2027-06-30')['conservation'];
    $q1row = collect($q1['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    expect(collect($q1row['obligation_details'])->filter(fn (array $item): bool => str_contains($item['label'], 'Regular PAMB Meetings')))->toBeEmpty();

    officeTatSetSchedule($actor, $area, [
        ['occurrence_key' => 'PAMB-2027-03-10-A', 'start_date' => '2027-03-10', 'deadline' => '2027-03-31'],
        ['occurrence_key' => 'PAMB-2027-03-18-B', 'start_date' => '2027-03-18', 'deadline' => '2027-03-31'],
    ], 'event', 'quarter_1', 2027, 1);
    $q1 = app(OfficeTatComplianceAssessmentService::class)->assessments('2027-H1', '2027-06-30')['conservation'];
    $q1row = collect($q1['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    $meetings = collect($q1row['obligation_details'])->filter(fn (array $item): bool => str_contains($item['label'], 'Regular PAMB Meetings'));
    expect($meetings)->toHaveCount(2)
        ->and($meetings->pluck('key')->unique()->count())->toBe(2)
        ->and($meetings->pluck('start_date')->all())->toBe(['2027-03-10', '2027-03-18']);
});

test('an exact approved historical non-receipt register resolves only its selected Development obligation', function (): void {
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    $service = app(OfficeTatComplianceAssessmentService::class);
    $assessment = $service->assessments('2026-H1', '2026-06-30')['development'];
    $row = collect($assessment['rows'])->firstWhere('name', 'CENRO Baganga');
    $candidate = collect($row['obligation_details'])->first(fn (array $item): bool => $item['deadline'] < '2026-10-11' && $item['received'] === null);
    $otherCandidate = collect($row['obligation_details'])->first(fn (array $item): bool => $item['key'] !== $candidate['key'] && $item['deadline'] < '2026-10-11' && $item['received'] === null);
    $cutoff = '2026-06-30';
    $approval = officeTatApprovalFields($actor, 'nonreceipt', ['participant_key' => $row['participant_key'], 'cutoff_date' => $cutoff]);
    $setup = app(OfficeTatObligationEvidenceSetupService::class);
    $register = $setup->saveNonReceiptRegister($actor, [
        'participant_key' => $row['participant_key'], 'cutoff_date' => $cutoff,
        'obligation_keys' => [$candidate['key']], 'expected_version' => 0, ...$approval,
    ], $row['obligation_details']);

    $after = $service->assessments('2026-H1', '2026-06-30')['development'];
    $afterRow = collect($after['rows'])->firstWhere('participant_key', $row['participant_key']);
    $resolved = collect($afterRow['obligation_details'])->firstWhere('key', $candidate['key']);
    $unresolved = collect($afterRow['obligation_details'])->firstWhere('key', $otherCandidate['key']);

    expect($register->obligation_keys)->toBe([$candidate['key']])
        ->and($register->obligation_manifest)->toHaveCount(1)
        ->and($register->obligation_manifest[0]['participant_key'])->toBe($row['participant_key'])
        ->and($register->obligation_manifest[0]['workflow_key'])->not->toBeEmpty()
        ->and($register->obligation_manifest[0]['activity_name'])->not->toBeEmpty()
        ->and($register->obligation_manifest[0]['document_type'])->not->toBeEmpty()
        ->and($register->obligation_manifest[0]['period_key'])->not->toBeEmpty()
        ->and($resolved['nonreceipt_verified'])->toBeTrue()
        ->and($resolved['nonreceipt_register_id'])->toBe($register->id)
        ->and($unresolved['nonreceipt_verified'])->toBeFalse()
        ->and($unresolved['status'])->toBe('Historical inventory unverified');

    try {
        $setup->saveNonReceiptRegister($actor, [
            'participant_key' => $row['participant_key'], 'cutoff_date' => $cutoff,
            'obligation_keys' => [$candidate['key']], 'expected_version' => 0, ...$approval,
        ], $row['obligation_details']);
        self::fail('A stale non-receipt register version must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('expected_version');
    }
});

test('Conservation 38 original receipt projection blocks non-receipt without changing live lifecycle dates', function (): void {
    $actor = User::factory()->create(['unit_assignment' => null, 'section' => 'CDS']);
    $actor->givePermissionTo(Permission::findOrCreate('office-tat.configure', 'web'));
    $area = complianceAssessmentArea('Conservation 38 Projection Test PA', 'Proposed', 'PAMO label');
    officeTatSetApplicability($actor, $area, 'applicable', '2026-01-01');

    $record = new ConservationReportSubmission();
    $record->forceFill([
        'id' => 38, 'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-03-18', 'date_accomplished' => '2026-03-18', 'date_received_penro' => null,
        'is_historical' => true,
    ])->save();
    HistoricalReportOrigin::query()->create([
        'source' => 'conservation', 'source_record_id' => 38, 'workflow_key' => 'regular_pamb', 'encoded_by' => $actor->id,
        'idempotency_key' => '123e4567-e89b-42d3-a456-426614174038', 'original_milestones' => [
            'provenance' => 'manually_recorded_original_dates', 'milestones' => [
                'date_report_released_cenro' => '2026-03-25', 'date_received_penro' => '2026-03-25', 'date_endorsed_regional' => '2026-03-31',
            ],
        ], 'encoded_at' => '2026-10-11 00:00:00',
    ]);
    officeTatSetSchedule($actor, $area, [[
        'occurrence_key' => 'PAMB-2026-03-18-ORIGINAL', 'start_date' => '2026-03-18', 'deadline' => '2026-03-31',
    ]], 'event', 'quarter_1', 2026);

    $result = app(OfficeTatComplianceAssessmentService::class)->assessments('2026-H1', '2026-06-30')['conservation'];
    $row = collect($result['rows'])->firstWhere('participant_key', 'pa:'.$area->id);
    $detail = collect($row['obligation_details'])->first(fn (array $item): bool => str_contains($item['label'], 'Regular PAMB Meetings'));
    $freshRecord = ConservationReportSubmission::query()->findOrFail(38);

    expect($detail['historical_receipt_evidence'])->toBe('2026-03-25')
        ->and($detail['status'])->toBe('Conflicting historical evidence')
        ->and($detail['issue'])->toContain('Original historical receipt')
        ->and($freshRecord->date_received_penro)->toBeNull()
        ->and((bool) $freshRecord->getAttribute('is_historical'))->toBeTrue()
        ->and($freshRecord->mov_file_path)->toBeNull();
});

test('source day conventions count Manila business dates through the shared calendar', function (): void {
    $service = app(OfficeTatComplianceAssessmentService::class);
    $method = new ReflectionMethod($service, 'durationDays');
    $start = CarbonImmutable::parse('2027-06-07', 'Asia/Manila');
    $end = CarbonImmutable::parse('2027-06-11', 'Asia/Manila');
    $standard = ['day_mode' => 'engp_standard_working_days', 'office' => 'CENRO Baganga'];
    $conservation = ['day_mode' => \App\Models\ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'office' => 'CENRO Baganga'];

    expect($method->invoke($service, $start, $end, $standard))->toBe(4)
        ->and($method->invoke($service, $start, $end, $conservation))->toBe(3);
});
