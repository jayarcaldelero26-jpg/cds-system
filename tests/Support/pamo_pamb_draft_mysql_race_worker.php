<?php

use App\Http\Controllers\PamoPambDraftController;
use App\Models\PamoPambDraft;
use App\Models\User;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';

try {
    $payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, flags: JSON_THROW_ON_ERROR);
    $taskRoot = realpath((string) ($payload['task_root'] ?? '')) ?: (string) ($payload['task_root'] ?? '');
    $expectedRoot = realpath($basePath.DIRECTORY_SEPARATOR.'.tmp').DIRECTORY_SEPARATOR.'cds-pamo-native-mysql-';
    $database = (string) getenv('CDS_ROUTING_MYSQL_TEST_DB');
    $databaseUrl = getenv('DB_URL');
    $ownerPath = $taskRoot.DIRECTORY_SEPARATOR.'ownership.json';
    $owner = is_file($ownerPath) ? json_decode((string) file_get_contents($ownerPath), true) : null;
    $marker = (string) getenv('CDS_PAMO_TASK_MARKER');
    if (! str_starts_with($taskRoot, $expectedRoot)
        || $taskRoot !== (realpath((string) getenv('CDS_PAMO_TASK_ROOT')) ?: (string) getenv('CDS_PAMO_TASK_ROOT'))
        || ! in_array((string) ($payload['mode'] ?? ''), ['forward', 'update'], true)
        || ! in_array((string) ($payload['role'] ?? ''), ['first', 'contender', 'replay'], true)
        || getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'mysql'
        || ($databaseUrl !== false && trim((string) $databaseUrl) !== '')
        || $database === ''
        || getenv('DB_DATABASE') !== $database
        || ! preg_match('/^cds_routing_uat_pamo_20261011_[a-f0-9]{8}$/', $database)
        || ! is_array($owner)
        || realpath((string) ($owner['task_root'] ?? '')) !== $taskRoot
        || ($owner['schema'] ?? null) !== $database
        || ($owner['task_id'] ?? null) !== getenv('CDS_PAMO_TASK_ID')
        || ($owner['marker'] ?? null) !== $marker
        || ($owner['server_uuid'] ?? null) !== getenv('CDS_PAMO_TASK_SERVER_UUID')
        || ! preg_match('/^[a-f0-9]{64}$/', $marker)) {
        throw new RuntimeException('The worker did not receive explicit isolated MySQL test settings.');
    }

    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=mysql');
    putenv('DB_DATABASE='.$database);
    putenv('DB_URL=');
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'mysql';
    $_ENV['DB_DATABASE'] = $database;
    $_ENV['DB_URL'] = '';

    $app = require $basePath.'/bootstrap/app.php';
    $storageRoot = $taskRoot.DIRECTORY_SEPARATOR.'laravel-storage';
    $app->useStoragePath($storageRoot);
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    if (app()->environment() !== 'testing' || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== $database || filled(config('database.connections.mysql.url'))) {
        throw new RuntimeException('The worker Laravel connection did not resolve to the explicit test database.');
    }
    $connection = DB::connection();
    $identity = $connection->selectOne('SELECT DATABASE() AS database_name, @@server_uuid AS server_uuid, @@transaction_isolation AS isolation_level');
    if ($identity->database_name !== $database || $identity->server_uuid !== $owner['server_uuid'] || $identity->isolation_level !== 'REPEATABLE-READ') {
        throw new RuntimeException('The worker live database identity/isolation did not match the isolated test schema.');
    }
    $markerHash = $connection->table('cds_task_ownership_marker')->where('task_id', $owner['task_id'])->value('marker_sha256');
    if (! hash_equals(hash('sha256', $marker), (string) $markerHash)) {
        throw new RuntimeException('The worker ownership marker did not match before its data queries.');
    }

    $controlRoot = $taskRoot.DIRECTORY_SEPARATOR.'control';
    $privateStorage = $taskRoot.DIRECTORY_SEPARATOR.'private-storage';
    config([
        'filesystems.disks.local.root' => $privateStorage,
        'services.document_archive.driver' => 'fake',
        'services.google_drive_archive.enabled' => true,
        'queue.default' => 'sync',
        'session.driver' => 'array',
        'mail.default' => 'array',
    ]);
    Storage::forgetDisk('local');
    Notification::fake();
    app()->instance(GoogleDriveArchiveGateway::class, new FakeDocumentArchiveGateway());

    $actor = User::query()->findOrFail((int) $payload['actor_id']);
    $draft = PamoPambDraft::query()->where('draft_uuid', (string) $payload['draft_uuid'])->firstOrFail();
    $workflow = (string) $payload['workflow'];
    $mode = (string) $payload['mode'];
    $data = ['lock_version' => (int) $payload['lock_version'], ...((array) ($payload['data'] ?? []))];
    $request = Request::create('/conservation-reports/pamo-drafts/'.$workflow.'/'.$draft->draft_uuid, $mode === 'update' ? 'PUT' : 'POST', $data);
    $request->headers->set('Referer', 'http://localhost/conservation-reports/'.$workflow);
    $request->setUserResolver(fn () => $actor);
    $session = app('session.store');
    if (method_exists($session, 'isStarted') && ! $session->isStarted()) $session->start();
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    URL::setRequest($request);
    Auth::guard('web')->setUser($actor);

    $role = (string) $payload['role'];
    $scenario = (string) $payload['scenario'];
    if ($role === 'first') {
        $lockHeld = false;
        DB::listen(function (QueryExecuted $query) use ($connection, $controlRoot, $scenario, &$lockHeld): void {
            $normalized = strtolower($query->sql);
            if ($lockHeld || ! str_contains($normalized, 'pamo_pamb_drafts') || ! str_contains($normalized, 'for update')) return;
            $lockHeld = true;
            file_put_contents($controlRoot.DIRECTORY_SEPARATOR.$scenario.'-first-draft-lock-held.json', 'held');
            $deadline = microtime(true) + 20;
            $release = $controlRoot.DIRECTORY_SEPARATOR.$scenario.'-release-draft-lock';
            while (! is_file($release)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Synthetic draft-lock overlap barrier timed out.');
                usleep(10_000);
            }
        });
    }
    if ($role === 'contender') {
        $connection->beforeExecuting(function (string $query) use ($controlRoot, $scenario): void {
            $normalized = strtolower($query);
            if (str_contains($normalized, 'pamo_pamb_drafts') && str_contains($normalized, 'for update')) {
                file_put_contents($controlRoot.DIRECTORY_SEPARATOR.$scenario.'-contender-lock-attempt.json', 'attempting');
            }
        });
    }

    try {
        $controller = app(PamoPambDraftController::class);
        $response = $mode === 'forward'
            ? $controller->forward($request, $workflow, $draft)
            : $controller->update($request, $workflow, $draft);
        $result = [
            'outcome' => 'success',
            'status' => $response->getStatusCode(),
            'forwarded_submission_id' => $session->get('forwarded_submission_id'),
        ];
    } catch (ValidationException $exception) {
        $result = ['outcome' => 'validation', 'errors' => $exception->errors()];
    } catch (AuthorizationException $exception) {
        $result = ['outcome' => 'denied', 'status' => 403];
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        $result = ['outcome' => 'denied', 'status' => $exception->getStatusCode()];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    $message = str_replace((string) (getenv('DB_DATABASE') ?: ''), '[owned test schema]', $exception->getMessage());
    $message = preg_replace('/(Host: )[^,]+/', '$1[redacted]', $message) ?: $message;
    echo json_encode(['outcome' => 'worker_error', 'type' => $exception::class, 'message' => mb_substr($message, 0, 900)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(1);
}
