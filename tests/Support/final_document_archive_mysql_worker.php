<?php

use App\Models\BmsReportSubmission;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\User;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SharedArchiveHarnessGateway;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';

try {
    $payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, flags: JSON_THROW_ON_ERROR);
    $taskRoot = realpath((string) (getenv('CDS_ARCHIVE_TASK_ROOT') ?: '')) ?: '';
    $expectedRoot = realpath($basePath.DIRECTORY_SEPARATOR.'.tmp').DIRECTORY_SEPARATOR.'cds-final-archive-mysql-';
    $schema = (string) getenv('CDS_ARCHIVE_MYSQL_TEST_DB');
    $ownerPath = $taskRoot.DIRECTORY_SEPARATOR.'ownership.json';
    $owner = is_file($ownerPath) ? json_decode((string) file_get_contents($ownerPath), true) : null;
    $marker = (string) getenv('CDS_ARCHIVE_TASK_MARKER');
    if (! str_starts_with($taskRoot, $expectedRoot)
        || ! is_array($owner)
        || ($owner['task_root'] ?? null) !== $taskRoot
        || ($owner['schema'] ?? null) !== $schema
        || ($owner['task_id'] ?? null) !== getenv('CDS_ARCHIVE_TASK_ID')
        || ($owner['marker'] ?? null) !== $marker
        || ! preg_match('/^cds_archive_uat_20261014_[a-f0-9]{8}$/', $schema)
        || ! preg_match('/^[a-f0-9]{64}$/', $marker)
        || getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'mysql'
        || getenv('DB_DATABASE') !== $schema
        || trim((string) getenv('DB_URL')) !== '') {
        throw new RuntimeException('The worker did not receive explicit owned MySQL settings.');
    }

    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=mysql');
    putenv('DB_DATABASE='.$schema);
    putenv('DB_URL=');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'mysql';
    $_ENV['DB_DATABASE'] = $schema;
    $_ENV['DB_URL'] = '';
    $app = require $basePath.'/bootstrap/app.php';
    $app->useStoragePath($taskRoot.DIRECTORY_SEPARATOR.'worker-storage');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $connection = DB::connection();
    $identity = $connection->selectOne('SELECT DATABASE() AS database_name, @@server_uuid AS server_uuid, @@transaction_isolation AS isolation_level');
    if ($identity->database_name !== $schema || $identity->server_uuid !== $owner['server_uuid'] || $identity->isolation_level !== 'REPEATABLE-READ') {
        throw new RuntimeException('The worker database identity or isolation did not match its owner marker.');
    }
    $markerHash = $connection->table('cds_task_ownership_marker')->where('task_id', $owner['task_id'])->value('marker_sha256');
    if (! hash_equals(hash('sha256', $marker), (string) $markerHash)) throw new RuntimeException('The worker marker check failed.');

    config([
        'services.document_archive.driver' => 'fake',
        'services.document_archive.final_return_archive_enabled' => true,
        'services.google_drive_archive.enabled' => true,
        'filesystems.disks.local.root' => $taskRoot.DIRECTORY_SEPARATOR.'private-storage',
        'cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'mail.default' => 'array',
    ]);
    Storage::forgetDisk('local');
    NotificationFacade::fake();
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, new SharedArchiveHarnessGateway());

    $mode = (string) ($payload['mode'] ?? '');
    $role = (string) ($payload['role'] ?? '');
    $signal = (string) ($payload['signal'] ?? '');
    $release = (string) ($payload['release'] ?? '');
    if ($role === 'first' && $signal !== '' && $release !== '') {
        $held = false;
        DB::listen(function (QueryExecuted $query) use ($taskRoot, $payload, $release, &$held): void {
            $normalized = strtolower($query->sql);
            if ($held || ! str_contains($normalized, 'bms_report_submissions') || ! str_contains($normalized, 'for update')) return;
            $held = true;
            file_put_contents((string) $payload['signal'], 'locked');
            $deadline = microtime(true) + 20;
            while (! is_file($release)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('MySQL row-lock barrier timed out.');
                usleep(10000);
            }
        });
    }
    if ($role === 'contender' && $signal !== '') {
        $connection->beforeExecuting(function (string $query) use ($signal): void {
            $normalized = strtolower($query);
            if (str_contains($normalized, 'bms_report_submissions') && str_contains($normalized, 'for update')) file_put_contents($signal, 'attempting');
        });
    }

    if ($mode === 'receive') {
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        if ($role === 'contender') $connection->statement('SET SESSION innodb_lock_wait_timeout = '.max(1, min(10, (int) (getenv('CDS_ARCHIVE_LOCK_WAIT_SECONDS') ?: 10))));
        $receipt = app(FinalDocumentArchiveService::class)->recordReceipt(
            $actor, 'bms', (int) $payload['source_id'], (string) $payload['physical_time'],
        );
        $result = ['outcome' => 'success', 'receipt_id' => (int) $receipt->getKey()];
    } elseif ($mode === 'archive') {
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        $definitions = $payload['uploads'] ?? [[
            'path' => $payload['upload_path'] ?? '',
            'filename' => $payload['filename'] ?? '',
        ]];
        if (! is_array($definitions) || $definitions === []) throw new RuntimeException('The harness upload set is empty.');
        $uploads = [];
        foreach ($definitions as $definition) {
            $path = is_array($definition) ? (string) ($definition['path'] ?? '') : '';
            $filename = is_array($definition) ? (string) ($definition['filename'] ?? '') : '';
            $realPath = realpath($path);
            if (! $realPath || ! is_file($realPath)
                || ! str_starts_with($realPath, $taskRoot.DIRECTORY_SEPARATOR)
                || $filename === '') {
                throw new RuntimeException('The harness upload input is missing or outside the owned task root.');
            }
            $uploads[] = new UploadedFile($realPath, $filename, 'application/pdf', UPLOAD_ERR_OK, true);
        }
        $result = app(FinalDocumentArchiveService::class)->createOrRetryManifest(
            $actor, 'bms', (int) $payload['source_id'], (string) $payload['intent_key'], $uploads, true,
        );
        $manifest = FinalDocumentArchiveManifest::query()->where('intent_key', (string) $payload['intent_key'])->firstOrFail();
        $result['manifest_id'] = (int) $manifest->getKey();
        $result['archive_status'] = $manifest->archive_status;
    } elseif ($mode === 'retry') {
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        $result = app(FinalDocumentArchiveService::class)->retry($actor, (int) $payload['manifest_id']);
    } elseif ($mode === 'hold_source_lock') {
        DB::transaction(function () use ($payload, $signal, $release): void {
            BmsReportSubmission::query()->lockForUpdate()->findOrFail((int) $payload['source_id']);
            file_put_contents($signal, 'locked');
            $deadline = microtime(true) + 20;
            while (! is_file($release)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Synthetic source row lock timed out.');
                usleep(10000);
            }
        });
        $result = ['outcome' => 'success', 'locked' => true];
    } else {
        throw new RuntimeException('The MySQL worker mode is not supported.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    $message = preg_replace('/(Host: )[^,]+/', '$1[redacted]', $exception->getMessage()) ?: $exception->getMessage();
    echo json_encode(['outcome' => 'error', 'type' => $exception::class, 'message' => mb_substr($message, 0, 900)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(1);
}
