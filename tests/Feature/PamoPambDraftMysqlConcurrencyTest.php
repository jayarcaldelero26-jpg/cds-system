<?php

namespace Tests\Feature;

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\PamoPambDraft;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

final class PamoPambDraftMysqlConcurrencyTest extends \Tests\TestCase
{
    #[Test]
    public function prerequisite_only_schema_fails_native_closed_and_keeps_generic_conservation_available(): void
    {
        if (getenv('CDS_PAMO_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires explicit opt-in to the owned disposable PAMO MySQL schema.');
        }
        $owner = $this->assertOwnedTestSchema();
        self::assertFalse(Schema::hasColumn('pamo_pamb_drafts', 'bms_report_submission_id'));
        self::assertFalse(app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->isSchemaReady());

        $runKey = Str::lower(Str::random(10));
        $taskRoot = $owner['task_root'];
        config([
            'filesystems.disks.local.root' => $taskRoot.DIRECTORY_SEPARATOR.'private-storage',
            'services.document_archive.driver' => 'fake',
            'services.google_drive_archive.enabled' => true,
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'session.driver' => 'array',
        ]);
        Storage::forgetDisk('local');
        if (! is_dir($taskRoot.DIRECTORY_SEPARATOR.'control')) mkdir($taskRoot.DIRECTORY_SEPARATOR.'control', 0700, true);
        Notification::fake();
        app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());

        [$actor, $area, $office] = $this->makeGenericFixture($runKey);
        $this->actingAs($actor)->get(route('pamo-native-reports.index', 'bms'))->assertForbidden();
        $this->actingAs($actor)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk();

        $partialToken = (string) Str::uuid();
        $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
            'start_idempotency_key' => $partialToken,
            'activity_name' => 'Regular PAMB',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $partial = PamoPambDraft::query()->where('start_idempotency_key', $partialToken)->firstOrFail();

        $forwardToken = (string) Str::uuid();
        $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.store', 'regular_pamb'), [
            'start_idempotency_key' => $forwardToken,
            'activity_name' => 'Regular PAMB', 'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 4', 'date_conducted' => '2026-10-01',
            'mov' => UploadedFile::fake()->createWithContent('synthetic-pamb.pdf', "%PDF-1.4\nPre-link fixture\n%%EOF"),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $forwarded = PamoPambDraft::query()->where('start_idempotency_key', $forwardToken)->firstOrFail();
        $startedAt = $forwarded->started_at?->toISOString();
        $payload = $forwarded->report_payload;
        $initialLockVersion = (int) $forwarded->lock_version;
        $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.forward', ['regular_pamb', $forwarded->draft_uuid]), [
            'lock_version' => $forwarded->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $forwarded->refresh();
        $source = ConservationReportSubmission::query()->findOrFail($forwarded->conservation_report_submission_id);

        $baseline = [
            'protected_area_id' => (int) $area->getKey(), 'pamo_office_id' => (int) $office->getKey(),
            'partial' => [
                'id' => (int) $partial->getKey(), 'draft_uuid' => $partial->draft_uuid,
                'status' => $partial->status, 'workflow_key' => $partial->workflow_key,
                'protected_area_id' => (int) $partial->protected_area_id, 'pamo_office_id' => (int) $partial->pamo_office_id,
                'lock_version' => (int) $partial->lock_version, 'started_at' => $partial->started_at?->toISOString(),
                'payload' => $partial->report_payload, 'conservation_report_submission_id' => $partial->conservation_report_submission_id,
            ],
            'forwarded' => [
                'id' => (int) $forwarded->getKey(), 'draft_uuid' => $forwarded->draft_uuid,
                'status' => $forwarded->status, 'workflow_key' => $forwarded->workflow_key,
                'protected_area_id' => (int) $forwarded->protected_area_id, 'pamo_office_id' => (int) $forwarded->pamo_office_id,
                'lock_version' => (int) $forwarded->lock_version, 'started_at' => $forwarded->started_at?->toISOString(),
                'initial_lock_version' => $initialLockVersion, 'payload' => $payload,
                'conservation_report_submission_id' => (int) $forwarded->conservation_report_submission_id,
                'source_activity_name' => $source->activity_name, 'source_target_office' => $source->target_office,
                'event_count' => (int) DB::table('document_routing_events')->where('source_type', 'conservation')->where('source_id', $source->getKey())->count(),
                'snapshot_origin_kind' => DB::table('submission_routing_snapshots')->where('source_key', 'conservation')->where('source_id', $source->getKey())->value('origin_kind'),
            ],
        ];
        self::assertSame('draft', $baseline['partial']['status']);
        self::assertNull($baseline['partial']['conservation_report_submission_id']);
        self::assertSame('forwarded', $baseline['forwarded']['status']);
        self::assertSame('pamo_pamb', $baseline['forwarded']['snapshot_origin_kind']);
        self::assertSame(1, $baseline['forwarded']['event_count']);
        file_put_contents($taskRoot.DIRECTORY_SEPARATOR.'conservation-draft-baseline.json', json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    #[Test]
    public function two_forward_races_preserve_a_single_source_handoff_and_snapshot(): void
    {
        if (getenv('CDS_PAMO_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires explicit opt-in to the owned disposable PAMO MySQL schema.');
        }

        $database = (string) config('database.connections.mysql.database');
        $requestedDatabase = (string) getenv('CDS_ROUTING_MYSQL_TEST_DB');
        $owner = $this->assertOwnedTestSchema();
        self::assertSame('testing', app()->environment(), 'PAMO MySQL workers require APP_ENV=testing.');
        self::assertSame('mysql', config('database.default'), 'PAMO MySQL workers must not fall back to SQLite.');
        self::assertSame($database, $requestedDatabase, 'The explicit test database must match the configured connection.');
        self::assertMatchesRegularExpression('/^cds_routing_uat_pamo_20261011_[a-f0-9]{8}$/', $database);
        self::assertEmpty(config('database.connections.mysql.url'), 'A conflicting database URL must be cleared.');
        $identity = DB::connection()->selectOne('SELECT DATABASE() AS database_name, @@transaction_isolation AS isolation_level');
        self::assertSame($database, $identity->database_name);
        self::assertSame('REPEATABLE-READ', $identity->isolation_level);
        self::assertTrue(Schema::hasTable('pamo_pamb_drafts'));
        self::assertTrue(DB::table('migrations')->where('migration', '2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity')->exists());

        $runKey = Str::lower(Str::random(10));
        $taskRoot = $owner['task_root'];
        $controlRoot = $taskRoot.DIRECTORY_SEPARATOR.'control';
        $storageRoot = $taskRoot.DIRECTORY_SEPARATOR.'private-storage';
        foreach ([$taskRoot, $controlRoot, $storageRoot] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                self::fail('Could not create the isolated PAMO race directories.');
            }
        }

        config([
            'filesystems.disks.local.root' => $storageRoot,
            'services.document_archive.driver' => 'fake',
            'services.google_drive_archive.enabled' => true,
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ]);
        Storage::forgetDisk('local');
        Notification::fake();
        app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());
        self::assertSame('fake', config('services.document_archive.driver'));

        try {
            [$actors, $area, $office] = $this->makeSameOfficeFixture($runKey);
            $supervisor = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
            $sourceBaseline = ConservationReportSubmission::query()->count();

            $forwardDraft = $this->makeCompleteDraft($runKey.'-duplicate', $area, $office, $actors[0]);
            [$forwardWinner, $forwardCoworker] = $this->runOverlap(
                $taskRoot,
                'concurrent-forward',
                'forward',
                $forwardDraft,
                $actors[0],
                'forward',
                $actors[1],
                $forwardDraft->lock_version,
            );
            self::assertSame('success', $forwardWinner['outcome']);
            self::assertSame('success', $forwardCoworker['outcome']);
            self::assertSame(302, $forwardWinner['status']);
            self::assertSame(302, $forwardCoworker['status']);
            self::assertSame($forwardWinner['forwarded_submission_id'], $forwardCoworker['forwarded_submission_id']);

            $forwardDraft->refresh();
            $forwardSource = ConservationReportSubmission::query()->findOrFail($forwardDraft->conservation_report_submission_id);
            $this->assertSinglePromotion($forwardDraft, $forwardSource, $area, $office, $actors[0], $supervisor, $sourceBaseline);

            $forwardReplay = $this->runWorker($taskRoot, 'forward-replay', 'forward', $forwardDraft, $actors[1], $forwardDraft->lock_version);
            self::assertSame('success', $forwardReplay['outcome']);
            self::assertSame(302, $forwardReplay['status']);
            self::assertSame((int) $forwardSource->getKey(), (int) $forwardReplay['forwarded_submission_id']);
            $this->assertSideEffectCounts($forwardDraft, 1, 1, 1);
            self::assertSame(1, ConservationReportSubmission::query()->count() - $sourceBaseline);

            $staleDraft = $this->makeCompleteDraft($runKey.'-stale', $area, $office, $actors[0]);
            $originalPayload = $staleDraft->report_payload;
            $originalStartedAt = $staleDraft->started_at?->toIso8601String();
            $sourceBeforeStaleRace = ConservationReportSubmission::query()->count();
            [$staleWinner, $staleCoworker] = $this->runOverlap(
                $taskRoot,
                'stale-save-forward',
                'forward',
                $staleDraft,
                $actors[0],
                'update',
                $actors[1],
                $staleDraft->lock_version,
                ['remarks' => 'stale coworker edit must not land'],
            );
            self::assertSame('success', $staleWinner['outcome']);
            self::assertSame(302, $staleWinner['status']);
            self::assertSame('validation', $staleCoworker['outcome']);
            self::assertArrayHasKey('draft', $staleCoworker['errors']);
            self::assertSame('A forwarded report cannot be edited as a draft.', $staleCoworker['errors']['draft'][0]);

            $staleDraft->refresh();
            $staleSource = ConservationReportSubmission::query()->findOrFail($staleDraft->conservation_report_submission_id);
            $expectedPayload = $originalPayload;
            $actualPayload = $staleDraft->report_payload;
            ksort($expectedPayload);
            ksort($actualPayload);
            self::assertSame('forwarded', $staleDraft->status);
            self::assertSame($expectedPayload, $actualPayload);
            self::assertSame($originalStartedAt, $staleDraft->started_at?->toIso8601String());
            self::assertSame((int) $actors[0]->getKey(), (int) $staleDraft->originator_user_id);
            self::assertSame((int) $actors[0]->getKey(), (int) $staleDraft->updated_by);
            $this->assertSinglePromotion($staleDraft, $staleSource, $area, $office, $actors[0], $supervisor, $sourceBeforeStaleRace);

            $staleReplay = $this->runWorker($taskRoot, 'stale-forward-replay', 'forward', $staleDraft, $actors[0], $staleDraft->lock_version);
            self::assertSame('success', $staleReplay['outcome']);
            self::assertSame(302, $staleReplay['status']);
            self::assertSame((int) $staleSource->getKey(), (int) $staleReplay['forwarded_submission_id']);
            $this->assertSideEffectCounts($staleDraft, 1, 1, 1);
            self::assertSame(1, ConservationReportSubmission::query()->count() - $sourceBeforeStaleRace);
        } finally {
            Storage::forgetDisk('local');
        }
    }

    #[Test]
    public function all_native_source_families_promote_once_under_overlapping_mysql_forward_requests(): void
    {
        if (getenv('CDS_PAMO_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires explicit opt-in to the owned disposable PAMO MySQL schema.');
        }

        $owner = $this->assertOwnedTestSchema();
        self::assertTrue(app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->isSchemaReady());
        $taskRoot = $owner['task_root'];
        config([
            'filesystems.disks.local.root' => $taskRoot.DIRECTORY_SEPARATOR.'private-storage',
            'services.document_archive.driver' => 'fake',
            'services.google_drive_archive.enabled' => true,
            'queue.default' => 'sync', 'mail.default' => 'array', 'session.driver' => 'array',
        ]);
        Storage::forgetDisk('local');
        Notification::fake();
        app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());

        $runKey = Str::lower(Str::random(10));
        [[$actor, $coworker], $area, $office] = $this->makeSameOfficeFixture($runKey);
        [$foreignActor] = $this->makeGenericFixture($runKey.'x');
        $cenro = User::factory()->create([
            'section' => OrganizationalAccessService::CENRO_FOCAL, 'unit_assignment' => null,
            'office_designated' => 'CENRO Baganga', 'is_active' => true, 'is_approved' => true,
        ]);
        foreach (['technical-reports.view', 'submission-tracking.view'] as $permission) {
            $cenro->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        $families = [
            'bms' => ['bms_report_submissions', 'bms_report_submission_id', 'bms', 'mov_file_path'],
            'bams' => ['bams_report_submissions', 'bams_report_submission_id', 'bams', 'mov_file_path'],
            'imea' => ['imea_report_submissions', 'imea_report_submission_id', 'imea', 'mov_file_path'],
            'imea_facility_maintenance' => ['imea_facility_maintenance_reports', 'imea_facility_maintenance_report_id', 'imea-maintenance', 'mov_file_path'],
            'automated_weather_station' => ['aws', 'aws_report_id', 'aws', 'report_file_path'],
            'ipaf_management' => ['ipaf_management_reports', 'ipaf_management_report_id', 'ipaf-management', 'mov_file_path'],
            'revenue_collection' => ['ipaf_revenue_collections', 'ipaf_revenue_collection_id', 'revenue', 'mov_file_path'],
        ];
        $requiredFields = [
            'bms' => 'activity_name', 'bams' => 'date_conducted_ranges', 'imea' => 'date_conducted_ranges',
            'imea_facility_maintenance' => 'quarter', 'automated_weather_station' => 'document_type',
            'ipaf_management' => 'date_conducted', 'revenue_collection' => 'reporting_month',
        ];
        $base = [
            'activity_name' => 'Synthetic native MySQL report', 'document_type' => 'Final Report',
            'semester' => '2nd Semester', 'date_conducted' => '2026-10-01',
            'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']],
            'date_accomplished' => '2026-10-03', 'remarks' => 'Synthetic isolated MySQL fixture',
        ];
        $payloads = [
            'bms' => $base,
            'bams' => $base,
            'imea' => $base,
            'imea_facility_maintenance' => [...$base, 'quarter' => 'Quarter 4'],
            'automated_weather_station' => [...$base, 'report_period_type' => 'Monthly', 'monitoring_period_start' => '2026-10-01', 'monitoring_period_end' => '2026-10-02'],
            'ipaf_management' => ['document_type' => 'Final Report', 'date_conducted' => '2026-10-01', 'date_accomplished' => '2026-10-03', 'remarks' => 'Synthetic isolated MySQL fixture'],
            'revenue_collection' => ['document_type' => 'Final Report', 'reporting_month' => 10, 'reporting_year' => 2026, 'total_collected' => '250.00', 'deadline_submission' => '2026-10-30', 'remarks' => 'Synthetic isolated MySQL fixture'],
        ];

        foreach ($families as $workflow => [$table, $linkColumn, $sourceKey, $pathColumn]) {
            $token = (string) Str::uuid();
            $upload = UploadedFile::fake()->createWithContent($workflow.'.pdf', "%PDF-1.4\nSynthetic isolated MySQL fixture\n%%EOF");
            $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.store', $workflow), [
                'start_idempotency_key' => $token,
                'mov' => $upload,
            ])->assertRedirect()->assertSessionHasNoErrors();
            $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
            self::assertSame((int) $area->getKey(), (int) $draft->protected_area_id);
            self::assertSame((int) $office->getKey(), (int) $draft->pamo_office_id);
            $sourceBaseline = DB::table($table)->count();
            $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.forward', [$workflow, $draft->draft_uuid]), [
                'lock_version' => $draft->lock_version,
            ])->assertSessionHasErrors($requiredFields[$workflow]);
            self::assertSame($sourceBaseline, DB::table($table)->count());
            self::assertSame('draft', $draft->fresh()->status);
            $this->actingAs($coworker)->put(route('conservation-reports.pamo-drafts.update', [$workflow, $draft->draft_uuid]), [
                'lock_version' => $draft->fresh()->lock_version, ...$payloads[$workflow],
            ])->assertRedirect()->assertSessionHasNoErrors();
            $draft->refresh();
            $scenario = 'native-'.$workflow.'-'.$runKey;
            [$first, $second] = $this->runOverlap($taskRoot, $scenario, 'forward', $draft, $actor, 'forward', $coworker, (int) $draft->lock_version);

            self::assertSame('success', $first['outcome']);
            self::assertContains($second['outcome'], ['success', 'validation']);
            $draft->refresh();
            $sourceId = (int) $draft->getAttribute($linkColumn);
            self::assertGreaterThan(0, $sourceId);
            self::assertSame('forwarded', $draft->status);
            self::assertSame($sourceBaseline + 1, DB::table($table)->count());
            $source = DB::table($table)->where('id', $sourceId)->first();
            self::assertNotNull($source);
            self::assertSame((int) $area->getKey(), (int) $source->protected_area_id);
            self::assertSame('CENRO Baganga', $source->target_office);
            self::assertNotEmpty($source->{$pathColumn});
            self::assertTrue(Storage::disk('local')->exists($source->{$pathColumn}));
            if ($workflow === 'automated_weather_station') self::assertNull($source->timestamps);
            self::assertSame(1, DB::table('document_routing_events')->where('source_type', $sourceKey)->where('source_id', $sourceId)->count());
            self::assertSame(1, DB::table('submission_routing_snapshots')->where('source_key', $sourceKey)->where('source_id', $sourceId)->count());
            self::assertSame('pamo_pamb', DB::table('submission_routing_snapshots')->where('source_key', $sourceKey)->where('source_id', $sourceId)->value('origin_kind'));
            self::assertSame(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, DB::table('document_routing_events')->where('source_type', $sourceKey)->where('source_id', $sourceId)->value('to_stage'));
            self::assertSame($sourceId, (int) ($first['forwarded_submission_id'] ?? $sourceId));
            if (($second['outcome'] ?? null) === 'success') self::assertSame($sourceId, (int) ($second['forwarded_submission_id'] ?? $sourceId));

            $this->actingAs($actor)->get(route('pamo-native-reports.mov', [$workflow, $sourceId]))->assertOk();
            $this->actingAs($coworker)->get(route('pamo-native-reports.mov', [$workflow, $sourceId]))->assertOk();
            $this->actingAs($foreignActor)->get(route('pamo-native-reports.mov', [$workflow, $sourceId]))->assertNotFound();
            $this->actingAs($cenro)->post(route('submission-tracking.transition', [$sourceKey, $sourceId, 'receive_at_cenro_focal']), [
                'stage' => 'receive_at_cenro_focal',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($cenro)->post(route('submission-tracking.transition', [$sourceKey, $sourceId, 'return_to_pamo_for_correction']), [
                'stage' => 'return_to_pamo_for_correction', 'remarks' => 'Synthetic correction.', 'correction_reason_key' => 'incomplete_document',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($actor)->post(route('submission-tracking.transition', [$sourceKey, $sourceId, 'receive_correction']), [
                'stage' => 'receive_correction',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $sourceDefinition = app(\App\Services\Pamo\PamoNativeSourceRegistry::class)->find($workflow);
            $model = $sourceDefinition['model'];
            $sourceModel = $model::query()->findOrFail($sourceId);
            $this->actingAs($actor)->put(route('pamo-native-reports.correction.update', [$workflow, $sourceId]), [
                ...$payloads[$workflow], 'source_updated_at' => $sourceModel->updated_at->toISOString(),
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($actor)->post(route('submission-tracking.transition', [$sourceKey, $sourceId, 'forward_from_pamo_to_cenro_focal']), [
                'stage' => 'forward_from_pamo_to_cenro_focal',
            ])->assertRedirect()->assertSessionHasNoErrors();
            self::assertSame(5, DB::table('document_routing_events')->where('source_type', $sourceKey)->where('source_id', $sourceId)->count());
            self::assertSame($sourceId, (int) $draft->fresh()->getAttribute($linkColumn));
            try {
                DB::table('pamo_pamb_drafts')->where('id', $draft->getKey())->update(['workflow_key' => $workflow === 'bms' ? 'bams' : 'bms']);
                self::fail('The MySQL immutable draft trigger accepted a workflow identity change.');
            } catch (\Illuminate\Database\QueryException) {
                self::assertSame($workflow, $draft->fresh()->workflow_key);
            }
        }

        $conflictToken = (string) Str::uuid();
        $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.store', 'bms'), [
            'start_idempotency_key' => $conflictToken,
            'mov' => UploadedFile::fake()->createWithContent('save-forward.pdf', "%PDF-1.4\nSave-forward race fixture\n%%EOF"),
            'activity_name' => 'Preserved BMS payload', 'document_type' => 'Final Report', 'semester' => '2nd Semester',
            'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']], 'date_accomplished' => '2026-10-03',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $conflictDraft = PamoPambDraft::query()->where('start_idempotency_key', $conflictToken)->firstOrFail();
        $originalPayload = $conflictDraft->report_payload;
        [$forwardResult, $saveResult] = $this->runOverlap(
            $taskRoot, 'save-forward-'.$runKey, 'forward', $conflictDraft, $actor, 'update', $coworker,
            (int) $conflictDraft->lock_version, ['remarks' => 'Competing saved value'],
        );
        self::assertSame('success', $forwardResult['outcome']);
        self::assertSame('validation', $saveResult['outcome']);
        self::assertSame('forwarded', $conflictDraft->fresh()->status);
        self::assertSame($originalPayload, $conflictDraft->fresh()->report_payload);
        self::assertSame(1, DB::table('bms_report_submissions')->where('id', $conflictDraft->fresh()->bms_report_submission_id)->count());
    }

    #[Test]
    public function native_mysql_promotion_rolls_back_after_routing_fault_and_retries_once(): void
    {
        if (getenv('CDS_PAMO_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires explicit opt-in to the owned disposable PAMO MySQL schema.');
        }
        $owner = $this->assertOwnedTestSchema();
        $taskRoot = $owner['task_root'];
        config(['filesystems.disks.local.root' => $taskRoot.DIRECTORY_SEPARATOR.'private-storage']);
        Storage::forgetDisk('local');
        Notification::fake();
        app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());
        [$actors, $area, $office] = $this->makeSameOfficeFixture(Str::lower(Str::random(10)));
        [$actor] = $actors;
        $token = (string) Str::uuid();
        $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.store', 'bms'), [
            'start_idempotency_key' => $token,
            'mov' => UploadedFile::fake()->createWithContent('rollback.pdf', "%PDF-1.4\nAtomic retry fixture\n%%EOF"),
            'activity_name' => 'Atomic BMS retry', 'document_type' => 'Final Report', 'semester' => '2nd Semester',
            'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']], 'date_accomplished' => '2026-10-03',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
        $sourceBaseline = DB::table('bms_report_submissions')->count();
        $eventBaseline = DB::table('document_routing_events')->count();
        $filesBefore = Storage::disk('local')->allFiles();
        $trigger = 'pamo_fault_'.Str::lower(Str::random(12));
        $this->assertOwnedTestSchema();
        DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `document_routing_events` FOR EACH ROW BEGIN IF NEW.source_type = 'bms' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic native routing fault'; END IF; END");
        try {
            $failed = $this->runWorker($taskRoot, 'fault-'.$trigger, 'forward', $draft, $actor, (int) $draft->lock_version);
            self::assertSame('worker_error', $failed['outcome']);
            $draft->refresh();
            self::assertSame('draft', $draft->status);
            self::assertNull($draft->bms_report_submission_id);
            self::assertSame($sourceBaseline, DB::table('bms_report_submissions')->count());
            self::assertSame($eventBaseline, DB::table('document_routing_events')->count());
            self::assertSame($filesBefore, Storage::disk('local')->allFiles(), 'Failed native promotion must remove uncommitted files. Worker: '.json_encode($failed));
        } finally {
            $this->assertOwnedTestSchema();
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        $retried = $this->runWorker($taskRoot, 'retry-'.$trigger, 'forward', $draft, $actor, (int) $draft->fresh()->lock_version);
        self::assertSame('success', $retried['outcome']);
        $draft->refresh();
        self::assertSame('forwarded', $draft->status);
        self::assertNotNull($draft->bms_report_submission_id);
        self::assertSame($sourceBaseline + 1, DB::table('bms_report_submissions')->count());
        self::assertSame($eventBaseline + 1, DB::table('document_routing_events')->count());
    }

    #[Test]
    public function native_mysql_first_handoffs_follow_each_pamo_pa_identity(): void
    {
        if (getenv('CDS_PAMO_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires explicit opt-in to the owned disposable PAMO MySQL schema.');
        }
        $owner = $this->assertOwnedTestSchema();
        config(['filesystems.disks.local.root' => $owner['task_root'].DIRECTORY_SEPARATOR.'private-storage']);
        Storage::forgetDisk('local');
        Notification::fake();
        app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());

        foreach ([
            'APL' => ['CENRO Baganga', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, 'pamo_regular'],
            'BMSFR' => ['CENRO Baganga', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, 'pamo_regular'],
            'BPL' => ['CENRO Baganga', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, 'pamo_regular'],
            'MPL' => ['CENRO Mati', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, 'pamo_regular'],
            'PBPLS' => ['CENRO Mati', DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, 'pamo_regular'],
            'MHRWS' => ['PENRO Davao Oriental', DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS, 'pamo_direct'],
        ] as $paCode => [$expectedOffice, $expectedStage, $expectedProfile]) {
            $runKey = Str::lower(Str::random(10));
            [[$actor], $area] = $this->makeSameOfficeFixture($runKey, $paCode);
            $token = (string) Str::uuid();
            $storeResponse = $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.store', 'bms'), [
                'start_idempotency_key' => $token,
                'mov' => UploadedFile::fake()->createWithContent('recipient.pdf', "%PDF-1.4\nRecipient route fixture\n%%EOF"),
                'activity_name' => 'Synthetic recipient route', 'document_type' => 'Final Report', 'semester' => '2nd Semester',
                'date_conducted_ranges' => [['from' => '2026-10-01', 'to' => '2026-10-02']], 'date_accomplished' => '2026-10-03',
            ]);
            self::assertTrue($storeResponse->isRedirect(), $paCode.' store returned '.$storeResponse->getStatusCode().': '.$storeResponse->getContent());
            $storeResponse->assertSessionHasNoErrors();
            $draft = PamoPambDraft::query()->where('start_idempotency_key', $token)->firstOrFail();
            $forwardResponse = $this->actingAs($actor)->post(route('conservation-reports.pamo-drafts.forward', ['bms', $draft->draft_uuid]), [
                'lock_version' => $draft->lock_version,
            ]);
            self::assertTrue($forwardResponse->isRedirect(), $paCode.' forward returned '.$forwardResponse->getStatusCode().': '.$forwardResponse->getContent());
            $forwardResponse->assertSessionHasNoErrors();
            $draft->refresh();
            $recordId = (int) $draft->bms_report_submission_id;
            $source = DB::table('bms_report_submissions')->where('id', $recordId)->firstOrFail();
            $event = DB::table('document_routing_events')->where('source_type', 'bms')->where('source_id', $recordId)->firstOrFail();
            $snapshot = DB::table('submission_routing_snapshots')->where('source_key', 'bms')->where('source_id', $recordId)->firstOrFail();
            self::assertSame((int) $area->getKey(), (int) $source->protected_area_id);
            self::assertSame($expectedOffice, $source->target_office);
            self::assertSame($expectedStage, $event->to_stage);
            self::assertSame($expectedProfile, $snapshot->profile);
        }
    }

    /**
     * The database is provisioned by the task runner. Every write-capable test
     * checks the same schema/server ownership marker before creating fixtures.
     *
     * @return array{task_root:string,task_id:string,marker:string,server_uuid:string}
     */
    private function assertOwnedTestSchema(): array
    {
        $taskRoot = realpath((string) getenv('CDS_PAMO_TASK_ROOT')) ?: (string) getenv('CDS_PAMO_TASK_ROOT');
        $ownerPath = $taskRoot.DIRECTORY_SEPARATOR.'ownership.json';
        self::assertSame('testing', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertSame('1', getenv('CDS_PAMO_MYSQL_INTEGRATION'));
        self::assertSame('1', getenv('CDS_ROUTING_MYSQL_INTEGRATION'));
        self::assertSame((string) config('database.connections.mysql.database'), (string) getenv('CDS_ROUTING_MYSQL_TEST_DB'));
        self::assertMatchesRegularExpression('/^cds_routing_uat_pamo_20261011_[a-f0-9]{8}$/', (string) config('database.connections.mysql.database'));
        self::assertStringStartsWith(realpath(base_path('.tmp')).DIRECTORY_SEPARATOR.'cds-pamo-native-mysql-', $taskRoot);
        self::assertFileExists($ownerPath);
        $owner = json_decode((string) file_get_contents($ownerPath), true);
        self::assertIsArray($owner);
        self::assertSame($taskRoot, realpath((string) ($owner['task_root'] ?? '')));
        self::assertSame((string) config('database.connections.mysql.database'), $owner['schema'] ?? null);
        self::assertSame($owner['schema'] ?? null, getenv('CDS_ROUTING_MYSQL_TEST_DB'));
        self::assertSame($owner['task_id'] ?? null, getenv('CDS_PAMO_TASK_ID'));
        self::assertSame($owner['marker'] ?? null, getenv('CDS_PAMO_TASK_MARKER'));
        self::assertSame($owner['server_uuid'] ?? null, getenv('CDS_PAMO_TASK_SERVER_UUID'));
        self::assertEmpty(config('database.connections.mysql.url'));
        $identity = DB::connection()->selectOne('SELECT DATABASE() AS database_name, @@server_uuid AS server_uuid, @@transaction_isolation AS isolation_level');
        self::assertSame($owner['schema'], $identity->database_name);
        self::assertSame($owner['server_uuid'], $identity->server_uuid);
        self::assertSame('REPEATABLE-READ', $identity->isolation_level);
        $hash = DB::table('cds_task_ownership_marker')->where('task_id', $owner['task_id'])->value('marker_sha256');
        self::assertTrue(hash_equals(hash('sha256', $owner['marker']), (string) $hash), 'The owned schema marker must verify before fixtures or test DDL.');

        return ['task_root' => $taskRoot, 'task_id' => $owner['task_id'], 'marker' => $owner['marker'], 'server_uuid' => $owner['server_uuid']];
    }

    /** @return array{0:User,1:ProtectedArea,2:OrganizationalOffice} */
    private function makeGenericFixture(string $runKey): array
    {
        $creator = User::factory()->create();
        $office = OrganizationalOffice::query()->create([
            'code' => 'pamo_mysql_'.$runKey, 'name' => 'Synthetic PAMO '.$runKey,
            'office_type' => 'pamo', 'is_active' => true,
        ]);
        $area = ProtectedArea::query()->create([
            'name' => 'Synthetic PA '.$runKey, 'short_name' => 'PM'.strtoupper(substr($runKey, 0, 8)),
            'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental',
            'region' => 'Region XI', 'status' => 'Active', 'pamo' => $office->name,
            'created_by' => $creator->getKey(), 'updated_by' => $creator->getKey(),
        ]);
        $supervisor = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
        foreach ([['pamo', $office], ['supervising', $supervisor]] as [$type, $assignedOffice]) {
            ProtectedAreaOfficeAssignment::query()->create([
                'protected_area_id' => $area->getKey(), 'organizational_office_id' => $assignedOffice->getKey(),
                'assignment_type' => $type, 'assigned_by' => $creator->getKey(),
            ]);
        }
        $actor = User::factory()->create([
            'name' => 'Synthetic PAMO '.$runKey, 'section' => OrganizationalAccessService::PAMO,
            'unit_assignment' => null, 'office_designated' => $office->name,
            'protected_area_id' => $area->getKey(), 'is_active' => true, 'is_approved' => true,
        ]);
        foreach (['technical-reports.view', 'submission-tracking.view'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        return [$actor, $area, $office];
    }

    /** @return array{0:array{0:User,1:User},1:ProtectedArea,2:OrganizationalOffice} */
    private function makeSameOfficeFixture(string $runKey, string $paCode = 'APL'): array
    {
        $owner = User::factory()->create();
        $office = OrganizationalOffice::query()->create([
            'code' => 'pamo_mysql_'.$runKey,
            'name' => 'Synthetic PAMO MySQL '.$runKey,
            'office_type' => 'pamo',
            'is_active' => true,
        ]);
        $area = ProtectedArea::query()->create([
            'name' => $paCode === 'MHRWS' ? 'Mt. Hamiguitan Range Wildlife Sanctuary' : 'Synthetic '.$paCode.' MySQL '.$runKey,
            'short_name' => $paCode,
            'category' => 'Protected Landscape',
            'municipality' => 'Baganga',
            'province' => 'Davao Oriental',
            'region' => 'Region XI',
            'status' => 'Active',
            'pamo' => $office->name,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ]);
        $supervisorCode = match ($paCode) {
            'MPL', 'PBPLS' => 'cenro_mati',
            'MHRWS' => 'penro_davao_oriental',
            default => 'cenro_baganga',
        };
        $supervisor = OrganizationalOffice::query()->where('code', $supervisorCode)->firstOrFail();
        foreach ([['pamo', $office], ['supervising', $supervisor]] as [$type, $assignedOffice]) {
            ProtectedAreaOfficeAssignment::query()->create([
                'protected_area_id' => $area->getKey(),
                'organizational_office_id' => $assignedOffice->getKey(),
                'assignment_type' => $type,
                'assigned_by' => $owner->getKey(),
            ]);
        }

        $actor = fn (string $label): User => User::factory()->create([
            'name' => 'Synthetic PAMO '.$label.' '.$runKey,
            'section' => OrganizationalAccessService::PAMO,
            'unit_assignment' => null,
            'office_designated' => $office->name,
            'protected_area_id' => $area->getKey(),
            'is_active' => true,
            'is_approved' => true,
        ]);
        $actors = [$actor('one'), $actor('two')];
        foreach ($actors as $pamoActor) {
            foreach (['technical-reports.view', 'submission-tracking.view'] as $permission) {
                $pamoActor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
            }
        }

        return [$actors, $area, $office];
    }

    private function makeCompleteDraft(string $suffix, ProtectedArea $area, OrganizationalOffice $office, User $originator): PamoPambDraft
    {
        $uuid = (string) Str::uuid();
        $path = 'pamo-pamb-drafts/'.$office->getKey().'/'.$area->getKey().'/'.$uuid.'/mov/synthetic.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nSynthetic native MySQL PAMB fixture {$suffix}\n%%EOF");

        return PamoPambDraft::query()->create([
            'draft_uuid' => $uuid,
            'start_idempotency_key' => (string) Str::uuid(),
            'protected_area_id' => $area->getKey(),
            'pamo_office_id' => $office->getKey(),
            'pamo_office_name' => $office->name,
            'originator_user_id' => $originator->getKey(),
            'created_by' => $originator->getKey(),
            'updated_by' => $originator->getKey(),
            'workflow_key' => 'regular_pamb',
            'report_payload' => [
                'activity_name' => 'Regular PAMB',
                'document_type' => 'Minutes',
                'reporting_period' => 'Quarter 4',
                'date_conducted' => '2026-10-01',
                'remarks' => 'original draft payload',
            ],
            'mov_path' => $path,
            'mov_file_name' => 'synthetic.pdf',
            'started_at' => now()->subMinutes(20),
            'status' => 'draft',
            'lock_version' => 1,
        ]);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function runOverlap(string $taskRoot, string $scenario, string $firstMode, PamoPambDraft $draft, User $firstActor, string $secondMode, User $secondActor, int $version, array $secondData = []): array
    {
        $controlRoot = $taskRoot.DIRECTORY_SEPARATOR.'control';
        if (! is_dir($controlRoot)) mkdir($controlRoot, 0700, true);
        $first = $this->startWorker($taskRoot, $scenario, 'first', $firstMode, $draft, $firstActor, $version);
        $second = null;
        $firstResult = null;
        $secondResult = null;
        try {
            $this->waitForSignal($first, $taskRoot.DIRECTORY_SEPARATOR.'control'.DIRECTORY_SEPARATOR.$scenario.'-first-draft-lock-held.json');
            $second = $this->startWorker($taskRoot, $scenario, 'contender', $secondMode, $draft, $secondActor, $version, $secondData);
            $this->waitForSignal($second, $taskRoot.DIRECTORY_SEPARATOR.'control'.DIRECTORY_SEPARATOR.$scenario.'-contender-lock-attempt.json');
            usleep(250_000);
            self::assertTrue(proc_get_status($second['process'])['running'], 'The competing request should be waiting on the draft row lock.');
        } catch (\Throwable $exception) {
            $this->touch($taskRoot.DIRECTORY_SEPARATOR.'control'.DIRECTORY_SEPARATOR.$scenario.'-release-draft-lock');
            if ($firstResult === null) $firstResult = $this->finishWorker($first, 20);
            if ($second !== null && $secondResult === null) $secondResult = $this->finishWorker($second, 20);
            throw $exception;
        }

        $this->touch($taskRoot.DIRECTORY_SEPARATOR.'control'.DIRECTORY_SEPARATOR.$scenario.'-release-draft-lock');
        $firstResult = $this->finishWorker($first, 20);
        $secondResult = $this->finishWorker($second, 20);
        return [$firstResult, $secondResult];
    }

    /** @return array<string,mixed> */
    private function runWorker(string $taskRoot, string $scenario, string $mode, PamoPambDraft $draft, User $actor, int $version): array
    {
        $worker = $this->startWorker($taskRoot, $scenario, 'replay', $mode, $draft, $actor, $version);
        return $this->finishWorker($worker, 20);
    }

    /** @return array{process:resource,stdout:resource,stderr:resource} */
    private function startWorker(string $taskRoot, string $scenario, string $role, string $mode, PamoPambDraft $draft, User $actor, int $version, array $data = []): array
    {
        $payload = [
            'task_root' => $taskRoot,
            'scenario' => $scenario,
            'role' => $role,
            'mode' => $mode,
            'workflow' => $draft->workflow_key,
            'draft_uuid' => $draft->draft_uuid,
            'actor_id' => (int) $actor->getKey(),
            'lock_version' => $version,
            'data' => $data,
        ];
        $script = base_path('tests/Support/pamo_pamb_draft_mysql_race_worker.php');
        $process = proc_open([PHP_BINARY, $script, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, base_path(), $this->workerEnvironment($taskRoot));
        if (! is_resource($process)) self::fail('Could not start an isolated PAMO MySQL worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function workerEnvironment(string $taskRoot): array
    {
        $environment = getenv();
        if (! is_array($environment)) $environment = [];
        $database = (string) config('database.connections.mysql.database');
        $connection = config('database.connections.mysql');
        $environment = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'CDS_ROUTING_MYSQL_INTEGRATION' => '1',
            'CDS_ROUTING_MYSQL_TEST_DB' => $database,
            'CDS_PAMO_MYSQL_INTEGRATION' => '1',
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'CDS_PAMO_TASK_ROOT' => $taskRoot,
            'CDS_PAMO_TASK_ID' => (string) (json_decode((string) file_get_contents($taskRoot.DIRECTORY_SEPARATOR.'ownership.json'), true)['task_id'] ?? ''),
            'CDS_PAMO_TASK_MARKER' => (string) (json_decode((string) file_get_contents($taskRoot.DIRECTORY_SEPARATOR.'ownership.json'), true)['marker'] ?? ''),
            'CDS_PAMO_TASK_SERVER_UUID' => (string) (json_decode((string) file_get_contents($taskRoot.DIRECTORY_SEPARATOR.'ownership.json'), true)['server_uuid'] ?? ''),
        ]);
        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'unix_socket' => 'DB_SOCKET'] as $key => $name) {
            if (array_key_exists($key, $connection) && $connection[$key] !== null) $environment[$name] = (string) $connection[$key];
        }
        return $environment;
    }

    private function waitForSignal(array &$worker, string $signalPath): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($signalPath) && microtime(true) < $deadline) {
            if (! proc_get_status($worker['process'])['running']) {
                $worker['captured_output'] = (string) (stream_get_contents($worker['stdout']) ?: '');
                $worker['captured_errors'] = (string) (stream_get_contents($worker['stderr']) ?: '');
                self::fail('A worker ended before reaching the overlap barrier: '.trim($worker['captured_output']).'; STDERR bytes: '.strlen($worker['captured_errors']));
            }
            usleep(10_000);
        }
        self::assertFileExists($signalPath, 'A PAMO MySQL worker did not reach its bounded overlap barrier.');
    }

    /** @return array<string,mixed> */
    private function finishWorker(array &$worker, int $timeoutSeconds): array
    {
        $output = (string) ($worker['captured_output'] ?? '');
        $errors = (string) ($worker['captured_errors'] ?? '');
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $output .= stream_get_contents($worker['stdout']) ?: '';
            $errors .= stream_get_contents($worker['stderr']) ?: '';
            $status = proc_get_status($worker['process']);
            if (! $status['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($worker['process']);
                self::fail('A PAMO MySQL worker exceeded its bounded completion time.');
            }
            usleep(10_000);
        }
        $output .= stream_get_contents($worker['stdout']) ?: '';
        $errors .= stream_get_contents($worker['stderr']) ?: '';
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        proc_close($worker['process']);
        $result = json_decode(trim($output), true);
        self::assertIsArray($result, 'PAMO MySQL worker did not return JSON. STDERR bytes: '.strlen($errors));
        return $result;
    }

    private function touch(string $path): void
    {
        file_put_contents($path, 'released');
    }

    private function assertSinglePromotion(PamoPambDraft $draft, ConservationReportSubmission $source, ProtectedArea $area, OrganizationalOffice $office, User $originator, OrganizationalOffice $supervisor, int $sourceBaseline): void
    {
        self::assertSame('forwarded', $draft->status);
        self::assertSame($sourceBaseline + 1, ConservationReportSubmission::query()->count());
        self::assertSame((int) $source->getKey(), (int) $draft->conservation_report_submission_id);
        self::assertSame($area->name, $source->protectedArea->name);
        self::assertSame($supervisor->name, $source->target_office);
        self::assertSideEffectCounts($draft, 1, 1, 1);
        $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $source->getKey())->firstOrFail();
        self::assertSame('pamo_pamb', $snapshot->origin_kind);
        self::assertSame((int) $area->getKey(), (int) $snapshot->protected_area_id);
        self::assertSame((int) $office->getKey(), (int) $snapshot->pamo_office_id);
        self::assertSame((int) $supervisor->getKey(), (int) $snapshot->responsible_cenro_office_id);
        self::assertSame((int) $originator->getKey(), (int) $snapshot->originator_user_id);
        self::assertSame($draft->started_at?->toDateTimeString(), $snapshot->origin_started_at?->toDateTimeString());
        $event = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $source->getKey())->firstOrFail();
        self::assertSame(DocumentRoutingProfileRegistry::PAMO_ORIGIN, $event->from_stage);
        self::assertSame(DocumentRoutingProfileRegistry::TRANSIT_PAMO_CENRO_FOCAL, $event->to_stage);
        self::assertSame('forward_from_pamo_to_cenro_focal', data_get($event->metadata, 'action_key'));
    }

    private function assertSideEffectCounts(PamoPambDraft $draft, int $sources, int $events, int $snapshots): void
    {
        $sourceId = (int) $draft->conservation_report_submission_id;
        self::assertGreaterThan(0, $sourceId);
        self::assertSame($sources, ConservationReportSubmission::query()->whereKey($sourceId)->count());
        self::assertSame($events, DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $sourceId)->count());
        self::assertSame($snapshots, SubmissionRoutingSnapshot::query()->where('source_key', 'conservation')->where('source_id', $sourceId)->count());
    }

}
