<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentArchive;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingAttachment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

function routingRaceState(BmsReportSubmission $record, array $actorIds): array
{
    $recordAttributes = $record->fresh()->getAttributes();
    $notifications = DB::table('notifications')
        ->where('notifiable_type', User::class)
        ->whereIn('notifiable_id', $actorIds)
        ->orderBy('id')
        ->get(['id', 'notifiable_id', 'data'])
        ->filter(function (object $notification) use ($record): bool {
            $payload = json_decode((string) $notification->data, true) ?: [];
            return ($payload['source_type'] ?? null) === 'bms'
                && (int) ($payload['source_id'] ?? 0) === (int) $record->getKey();
        })
        ->map(fn (object $notification): array => [
            'id' => (string) $notification->id,
            'notifiable_id' => (int) $notification->notifiable_id,
            'data' => json_decode((string) $notification->data, true) ?: [],
        ])
        ->values()
        ->all();

    return [
        'source' => 'bms',
        'source_id' => (int) $record->getKey(),
        'events' => DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $record->getKey())
            ->orderBy('id')->get()->map(fn (DocumentRoutingEvent $event): array => $event->getAttributes())->all(),
        'snapshots' => SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $record->getKey())
            ->orderBy('id')->get()->map(fn (SubmissionRoutingSnapshot $snapshot): array => $snapshot->getAttributes())->all(),
        // Keep every source-row field so receipt milestones, dates and official-document references are compared.
        'source_record' => $recordAttributes,
        'attachments' => SubmissionRoutingAttachment::query()->where('source', 'bms')->where('source_id', $record->getKey())
            ->orderBy('id')->get()->map(fn (SubmissionRoutingAttachment $attachment): array => $attachment->getAttributes())->all(),
        'archives' => DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->getKey())
            ->orderBy('id')->get()->map(fn (DocumentArchive $archive): array => $archive->getAttributes())->all(),
        'notifications' => $notifications,
        'jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
        'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
    ];
}

function routingRaceWaitForFile(string $path, Process $process, int $timeoutSeconds = 45): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (! is_file($path)) {
        if (! $process->isRunning()) {
            throw new RuntimeException('Routing race worker exited before its barrier: '.$process->getErrorOutput().$process->getOutput());
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for routing race barrier '.$path);
        }
        usleep(10_000);
    }

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function routingRaceWaitForLockWait(string $databaseName): void
{
    $deadline = microtime(true) + 20;
    do {
        $wait = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS wait_count
            FROM performance_schema.data_lock_waits AS waits
            INNER JOIN performance_schema.data_locks AS requested
                ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
            WHERE requested.OBJECT_SCHEMA = ?
              AND requested.OBJECT_NAME = 'bms_report_submissions'
        SQL, [$databaseName]);
        if ((int) $wait->wait_count > 0) return;
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('The duplicate request did not appear in performance_schema as waiting on the BMS report-row lock.');
}

function routingRaceActor(string $category): User
{
    $actor = User::factory()->create([
        'section' => $category,
        'office_designated' => 'CENRO Mati',
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'is_active' => true,
        'is_approved' => true,
    ]);
    $actor->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
    $actor->givePermissionTo(Permission::findOrCreate('submission-tracking.view', 'web'));

    return $actor;
}

test('outer-transaction duplicate BMS actions see committed routing events and snapshots under mysql repeatable read', function (): void {
    $connection = DB::connection();
    $databaseName = (string) config('database.connections.'.config('database.default').'.database');
    if ($connection->getDriverName() !== 'mysql'
        || app()->environment() !== 'testing'
        || ! str_starts_with($databaseName, 'cds_routing_uat_')) {
        $this->markTestSkipped('This race regression only runs against a disposable MySQL safety database.');
    }

    $identity = $connection->selectOne('SELECT DATABASE() AS database_name, @@port AS port, @@transaction_isolation AS isolation_level');
    expect($identity->database_name)->toBe($databaseName)
        ->and((int) $identity->port)->toBe(3306)
        ->and($identity->isolation_level)->toBe('REPEATABLE-READ');

    config([
        'services.document_archive.driver' => 'fake',
        'services.google_drive_archive.enabled' => true,
        'queue.default' => 'sync',
    ]);
    app(GoogleDriveArchiveGateway::class);

    $suffix = bin2hex(random_bytes(5));
    $owner = User::factory()->create(['section' => 'CDS', 'office_designated' => 'PENRO Davao Oriental']);
    $focal = routingRaceActor(OrganizationalAccessService::CENRO_FOCAL);
    $chief = routingRaceActor(OrganizationalAccessService::CENRO_CHIEF);
    $area = ProtectedArea::query()->create([
        'name' => 'Synthetic routing race '.$suffix,
        'short_name' => 'RR'.strtoupper(substr($suffix, 0, 4)),
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $owner->getKey(),
        'updated_by' => $owner->getKey(),
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->getKey(),
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
        'assigned_by' => $owner->getKey(),
    ]);
    $createReport = fn (string $name): BmsReportSubmission => BmsReportSubmission::query()->create([
        'protected_area_id' => $area->getKey(),
        'target_office' => 'CENRO Mati',
        'activity_name' => $name,
        'document_type' => 'Report',
        'semester' => '1st Semester 2026',
        'date_accomplished' => '2026-08-03',
    ]);
    $existingRoute = $createReport('Synthetic already captured BMS route '.$suffix);
    $firstActionRoute = $createReport('Synthetic first-action BMS route '.$suffix);

    $initial = app(DocumentRoutingTransitionService::class)->transition(
        $existingRoute,
        'bms',
        'forward_to_cenro_chief',
        (int) $focal->getKey(),
    );
    expect($initial->to_stage)->toBe(DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)
        ->and(SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $existingRoute->getKey())->count())->toBe(1);

    $actorIds = [(int) $focal->getKey(), (int) $chief->getKey()];
    $scenarios = [
        [
            'label' => 'captured-route',
            'record' => $existingRoute,
            'action' => 'receive_at_cenro_chief',
            'actor' => $chief,
            'expected_events_before' => 1,
            'expected_snapshots_before' => 1,
        ],
        [
            'label' => 'first-action',
            'record' => $firstActionRoute,
            'action' => 'forward_to_cenro_chief',
            'actor' => $focal,
            'expected_events_before' => 0,
            'expected_snapshots_before' => 0,
        ],
    ];
    $observations = [];
    $raceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cds-routing-duplicate-race-'.bin2hex(random_bytes(8));
    (new Illuminate\Filesystem\Filesystem())->ensureDirectoryExists($raceRoot);
    $outerTransactionCommitted = false;
    $monitorConnection = 'routing_race_monitor';
    config(['database.connections.'.$monitorConnection => config('database.connections.'.config('database.default'))]);
    DB::purge($monitorConnection);

    try {
        // The controller's transitionWithAttachment() starts its transaction before
        // SubmissionTrackingService::transition() performs this ordinary source lookup.
        // Commit only these synthetic fixtures so independent PHP workers can see them.
        $connection->commit();
        $outerTransactionCommitted = true;

        foreach ($scenarios as $scenario) {
            /** @var BmsReportSubmission $record */
            $record = $scenario['record'];
            /** @var User $actor */
            $actor = $scenario['actor'];
            $raceDirectory = $raceRoot.DIRECTORY_SEPARATOR.$scenario['label'];
            (new Illuminate\Filesystem\Filesystem())->ensureDirectoryExists($raceDirectory);
            $before = routingRaceState($record, $actorIds);
            expect(count($before['events']))->toBe($scenario['expected_events_before'])
                ->and(count($before['snapshots']))->toBe($scenario['expected_snapshots_before']);

            $workerEnvironment = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $databaseName,
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3306',
                'DB_URL' => '',
                'LARAVEL_STORAGE_PATH' => $raceDirectory.DIRECTORY_SEPARATOR.'storage',
                'ROUTING_RACE_DIRECTORY' => $raceDirectory,
            ];
            $workerScript = base_path('tests/Support/routing_transition_race_worker.php');
            $winner = new Process([
                PHP_BINARY, $workerScript, 'winner', $scenario['action'],
                (string) $record->getKey(), (string) $actor->getKey(),
            ], base_path(), $workerEnvironment);
            $winner->setTimeout(120);
            $duplicate = new Process([
                PHP_BINARY, $workerScript, 'duplicate', $scenario['action'],
                (string) $record->getKey(), (string) $actor->getKey(),
            ], base_path(), $workerEnvironment);
            $duplicate->setTimeout(120);

            try {
                $winner->start();
                $winnerHeld = routingRaceWaitForFile($raceDirectory.DIRECTORY_SEPARATOR.'winner-held.json', $winner);
                expect($winnerHeld['database'])->toBe($databaseName)
                    ->and((int) $winnerHeld['port'])->toBe(3306)
                    ->and($winnerHeld['isolation'])->toBe('REPEATABLE-READ')
                    ->and((int) $winnerHeld['transaction_level'])->toBeGreaterThan(0);

                $duplicate->start();
                $oldView = routingRaceWaitForFile($raceDirectory.DIRECTORY_SEPARATOR.'duplicate-source-read.json', $duplicate);
                expect($oldView['database'])->toBe($databaseName)
                    ->and((int) $oldView['port'])->toBe(3306)
                    ->and($oldView['isolation'])->toBe('REPEATABLE-READ')
                    ->and((int) $oldView['transaction_level'])->toBeGreaterThan(0)
                    ->and(count($oldView['event_ids_visible_before_report_lock']))->toBe($scenario['expected_events_before'])
                    ->and(count($oldView['snapshot_ids_visible_before_report_lock']))->toBe($scenario['expected_snapshots_before']);

                $monitor = DB::connection($monitorConnection);
                $monitorIdentity = $monitor->selectOne('SELECT DATABASE() AS database_name, @@port AS port');
                expect($monitorIdentity->database_name)->toBe($databaseName)->and((int) $monitorIdentity->port)->toBe(3306);
                routingRaceWaitForLockWait($databaseName);

                file_put_contents($raceDirectory.DIRECTORY_SEPARATOR.'release-winner', 'release');
                routingRaceWaitForFile($raceDirectory.DIRECTORY_SEPARATOR.'winner-result.json', $winner);
                $winner->wait();
                expect($winner->getExitCode())->toBe(0);
                $winnerResult = json_decode((string) file_get_contents($raceDirectory.DIRECTORY_SEPARATOR.'winner-result.json'), true, 512, JSON_THROW_ON_ERROR);
                expect($winnerResult['status'])->toBe('success');

                routingRaceWaitForFile($raceDirectory.DIRECTORY_SEPARATOR.'duplicate-report-lock-acquired.json', $duplicate);
                $afterWinner = routingRaceState($record, $actorIds);
                expect(count($afterWinner['events']))->toBe($scenario['expected_events_before'] + 1)
                    ->and(count($afterWinner['snapshots']))->toBe(1)
                    ->and(count($afterWinner['archives']))->toBe(count($before['archives']))
                    ->and(count($afterWinner['attachments']))->toBe(count($before['attachments']));

                file_put_contents($raceDirectory.DIRECTORY_SEPARATOR.'continue-duplicate', 'continue');
                routingRaceWaitForFile($raceDirectory.DIRECTORY_SEPARATOR.'duplicate-result.json', $duplicate);
                $duplicate->wait();
                expect($duplicate->getExitCode())->toBe(0);
                $duplicateResult = json_decode((string) file_get_contents($raceDirectory.DIRECTORY_SEPARATOR.'duplicate-result.json'), true, 512, JSON_THROW_ON_ERROR);
                $afterDuplicate = routingRaceState($record, $actorIds);

                $observations[$scenario['label']] = [
                    'old_view' => $oldView,
                    'before' => $before,
                    'after_winner' => $afterWinner,
                    'after_duplicate' => $afterDuplicate,
                    'winner_result' => $winnerResult,
                    'duplicate_result' => $duplicateResult,
                ];

                if ($evidenceDirectory = getenv('ROUTING_RACE_EVIDENCE_DIRECTORY')) {
                    if (! is_dir($evidenceDirectory)) throw new RuntimeException('The requested isolated evidence directory does not exist.');
                    file_put_contents(
                        $evidenceDirectory.DIRECTORY_SEPARATOR.'v1-'.$scenario['label'].'.json',
                        json_encode($observations[$scenario['label']], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    );
                }
            } finally {
                if ($winner->isRunning()) file_put_contents($raceDirectory.DIRECTORY_SEPARATOR.'release-winner', 'release');
                if ($duplicate->isRunning()) file_put_contents($raceDirectory.DIRECTORY_SEPARATOR.'continue-duplicate', 'continue');
                if ($winner->isRunning()) $winner->stop(2);
                if ($duplicate->isRunning()) $duplicate->stop(2);
            }
        }

        foreach ($scenarios as $scenario) {
            $observation = $observations[$scenario['label']];
            expect($observation['duplicate_result']['status'])->toBe('stale_action_rejected')
                ->and($observation['duplicate_result']['errors']['stage'][0] ?? null)->toBe('This document is no longer awaiting that routing action.')
                ->and($observation['after_duplicate'])->toEqual($observation['after_winner'])
                ->and(count($observation['after_duplicate']['events']))->toBe($scenario['expected_events_before'] + 1)
                ->and(count($observation['after_duplicate']['snapshots']))->toBe(1);
        }
    } finally {
        DB::disconnect($monitorConnection);
        DB::purge($monitorConnection);

        if ($outerTransactionCommitted) {
            DB::transaction(function () use ($existingRoute, $firstActionRoute, $area, $owner, $focal, $chief, $actorIds): void {
                $recordIds = [(int) $existingRoute->getKey(), (int) $firstActionRoute->getKey()];
                DocumentRoutingEvent::query()->where('source_type', 'bms')->whereIn('source_id', $recordIds)->delete();
                SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->whereIn('source_id', $recordIds)->delete();
                SubmissionRoutingAttachment::query()->where('source', 'bms')->whereIn('source_id', $recordIds)->delete();
                DocumentArchive::query()->where('source_type', 'bms')->whereIn('source_id', $recordIds)->delete();
                DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $actorIds)->delete();
                BmsReportSubmission::query()->whereIn('id', $recordIds)->delete();
                ProtectedAreaOfficeAssignment::query()->where('protected_area_id', $area->getKey())->delete();
                ProtectedArea::withTrashed()->whereKey($area->getKey())->forceDelete();
                DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $actorIds)->delete();
                User::query()->whereIn('id', [...$actorIds, (int) $owner->getKey()])->delete();
            });
        }

        (new Illuminate\Filesystem\Filesystem())->deleteDirectory($raceRoot);
        if ($connection->transactionLevel() === 0) $connection->beginTransaction();
    }
});
