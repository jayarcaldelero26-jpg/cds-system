<?php

use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\SubmissionTracking\RoutingTransitionLifecycle;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function raceWorkerWrite(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function raceWorkerWait(string $path): void
{
    $deadline = microtime(true) + 90;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Timed out waiting on race barrier.');
        usleep(10_000);
    }
}

$role = (string) ($argv[1] ?? '');
$action = (string) ($argv[2] ?? '');
$reportId = (int) ($argv[3] ?? 0);
$actorId = (int) ($argv[4] ?? 0);
$raceDirectory = (string) getenv('ROUTING_RACE_DIRECTORY');
$databaseName = (string) getenv('DB_DATABASE');
$suffix = $role.'-'.$action;

try {
    if (! in_array($role, ['winner', 'duplicate'], true)
        || ! in_array($action, ['forward_to_cenro_chief', 'receive_at_cenro_chief'], true)
        || $reportId < 1 || $actorId < 1 || ! is_dir($raceDirectory)) {
        throw new RuntimeException('Invalid routing race worker input.');
    }

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    if ($storagePath = getenv('LARAVEL_STORAGE_PATH')) $app->useStoragePath($storagePath);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    if (app()->environment() !== 'testing'
        || config('database.default') !== 'mysql'
        || ! str_starts_with($databaseName, 'cds_routing_uat_')) {
        throw new RuntimeException('The worker is not attached to the isolated MySQL test runtime.');
    }

    config([
        'services.document_archive.driver' => 'fake',
        'services.google_drive_archive.enabled' => true,
        'queue.default' => 'sync',
    ]);
    app(GoogleDriveArchiveGateway::class);
    Storage::fake('local');

    $connection = DB::connection();
    $identity = $connection->selectOne('SELECT DATABASE() AS database_name, @@port AS port, @@transaction_isolation AS isolation_level');
    if ($identity->database_name !== $databaseName
        || (int) $identity->port !== 3306
        || $identity->isolation_level !== 'REPEATABLE-READ') {
        throw new RuntimeException('Database identity/isolation verification failed in race worker.');
    }

    $sourceReadObserved = false;
    $lockedRecordObserved = false;
    $measuringReadView = false;
    DB::listen(function (QueryExecuted $query) use (&$sourceReadObserved, &$lockedRecordObserved, &$measuringReadView, $role, $raceDirectory, $databaseName, $reportId, $identity): void {
        if ($measuringReadView) return;
        $sql = strtolower($query->sql);
        if (! str_contains($sql, 'bms_report_submissions')) return;

        if ($role === 'duplicate'
            && ! $sourceReadObserved
            && str_starts_with(ltrim($sql), 'select')
            && ! str_contains($sql, 'for update')) {
            $sourceReadObserved = true;
            $measuringReadView = true;
            try {
                $events = DB::table('document_routing_events')
                    ->where('source_type', 'bms')->where('source_id', $reportId)->orderBy('id')->pluck('id')->all();
                $snapshots = DB::table('submission_routing_snapshots')
                    ->where('source_key', 'bms')->where('source_id', $reportId)->pluck('id')->all();
            } finally {
                $measuringReadView = false;
            }
            raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.'duplicate-source-read.json', [
                'database' => $databaseName,
                'port' => (int) $identity->port,
                'isolation' => $identity->isolation_level,
                'transaction_level' => DB::transactionLevel(),
                'event_ids_visible_before_report_lock' => array_map('intval', $events),
                'snapshot_ids_visible_before_report_lock' => array_map('intval', $snapshots),
                'sql' => $query->sql,
            ]);
        }

        if ($role === 'duplicate'
            && ! $lockedRecordObserved
            && str_starts_with(ltrim($sql), 'select')
            && str_contains($sql, 'for update')) {
            $lockedRecordObserved = true;
            raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.'duplicate-report-lock-acquired.json', [
                'database' => $databaseName,
                'port' => (int) $identity->port,
                'isolation' => $identity->isolation_level,
                'transaction_level' => DB::transactionLevel(),
            ]);
            raceWorkerWait($raceDirectory.DIRECTORY_SEPARATOR.'continue-duplicate');
        }
    });

    if (DB::transactionLevel() !== 0) throw new RuntimeException('Unexpected transaction before the outer controller transaction.');
    $connection->beginTransaction();
    if (DB::transactionLevel() < 1) throw new RuntimeException('The worker did not enter its outer transaction.');

    try {
        $event = app(SubmissionTrackingService::class)->transition('bms', $reportId, $action, null, $actorId);
        if (! $event instanceof \App\Models\DocumentRoutingEvent) throw new RuntimeException('The generic transition did not return its event.');
        $actor = User::query()->findOrFail($actorId);
        app(RoutingTransitionLifecycle::class)->afterTransition($event, $actor);

        if ($role === 'winner') {
            raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.'winner-held.json', [
                'database' => $databaseName,
                'port' => (int) $identity->port,
                'isolation' => $identity->isolation_level,
                'transaction_level' => DB::transactionLevel(),
                'event_id' => (int) $event->getKey(),
                'action' => $action,
            ]);
            raceWorkerWait($raceDirectory.DIRECTORY_SEPARATOR.'release-winner');
        }

        $connection->commit();
        raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.$role.'-result.json', [
            'status' => 'success',
            'event_id' => (int) $event->getKey(),
            'action' => $action,
            'transaction_level_after_commit' => DB::transactionLevel(),
        ]);
    } catch (ValidationException $exception) {
        while ($connection->transactionLevel() > 0) $connection->rollBack();
        raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.$role.'-result.json', [
            'status' => 'stale_action_rejected',
            'errors' => $exception->errors(),
        ]);
    } catch (Throwable $exception) {
        while ($connection->transactionLevel() > 0) $connection->rollBack();
        raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.$role.'-result.json', [
            'status' => 'error',
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
} catch (Throwable $exception) {
    raceWorkerWrite($raceDirectory.DIRECTORY_SEPARATOR.$suffix.'-worker-error.json', [
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ]);
    exit(1);
}
