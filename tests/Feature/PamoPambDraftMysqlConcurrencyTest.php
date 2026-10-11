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
use PHPUnit\Framework\Attributes\Test;

final class PamoPambDraftMysqlConcurrencyTest extends \Tests\TestCase
{
    #[Test]
    public function two_forward_races_preserve_a_single_source_handoff_and_snapshot(): void
    {
        if (getenv('CDS_PAMO_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires explicit opt-in to the owned disposable PAMO MySQL schema.');
        }

        $database = (string) config('database.connections.mysql.database');
        $requestedDatabase = (string) getenv('CDS_ROUTING_MYSQL_TEST_DB');
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
        $taskRoot = base_path('.tmp'.DIRECTORY_SEPARATOR.'pamo-pilot-mysql-'.$runKey);
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
            self::assertSame('A forwarded PAMB report cannot be edited as a draft.', $staleCoworker['errors']['draft'][0]);

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
            $expectedRoot = base_path('.tmp'.DIRECTORY_SEPARATOR.'pamo-pilot-mysql-');
            if (str_starts_with($taskRoot, $expectedRoot) && is_dir($taskRoot)) {
                \Illuminate\Support\Facades\File::deleteDirectory($taskRoot);
            }
        }
    }

    /** @return array{0:array{0:User,1:User},1:ProtectedArea,2:OrganizationalOffice} */
    private function makeSameOfficeFixture(string $runKey): array
    {
        $owner = User::factory()->create();
        $office = OrganizationalOffice::query()->create([
            'code' => 'pamo_mysql_'.$runKey,
            'name' => 'Synthetic PAMO MySQL '.$runKey,
            'office_type' => 'pamo',
            'is_active' => true,
        ]);
        $area = ProtectedArea::query()->create([
            'name' => 'Synthetic PAMB MySQL '.$runKey,
            'short_name' => 'PM'.strtoupper(substr($runKey, 0, 8)),
            'category' => 'Protected Landscape',
            'municipality' => 'Baganga',
            'province' => 'Davao Oriental',
            'region' => 'Region XI',
            'status' => 'Active',
            'pamo' => $office->name,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ]);
        $supervisor = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
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

        return [[$actor('one'), $actor('two')], $area, $office];
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
        ], $pipes, base_path(), $this->workerEnvironment());
        if (! is_resource($process)) self::fail('Could not start an isolated PAMO MySQL worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function workerEnvironment(): array
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
