<?php

use App\Http\Controllers\SubmissionTrackingController;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

final class DispatchInProgressDelayedFakeGateway implements GoogleDriveArchiveGateway
{
    private array $objects = [];

    public function __construct(private readonly array $payload) {}

    public function findByIdentityAndHash(array $identity, string $sha256): ?array { return null; }

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $root = $this->payload['task_root'].DIRECTORY_SEPARATOR.'control';
        $log = fopen($root.DIRECTORY_SEPARATOR.'uploads.log', 'ab');
        if ($log) {
            flock($log, LOCK_EX);
            fwrite($log, $this->payload['scenario']."\n");
            fflush($log);
            flock($log, LOCK_UN);
            fclose($log);
        }
        file_put_contents($root.DIRECTORY_SEPARATOR.'upload-'.$this->payload['scenario'].'.started', 'started');
        usleep(((int) ($this->payload['delay_ms'] ?? 0)) * 1000);
        if ($this->payload['fail_upload'] ?? false) throw new RuntimeException('Synthetic archive provider failure.');

        $fileId = 'synthetic-archive-'.$this->payload['scenario'];
        $this->objects[$fileId] = ['bytes' => file_get_contents($localPath), 'sha256' => $sha256, 'folder_id' => $folderId];
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    public function verify(string $fileId, string $sha256, int $size): bool
    {
        return $this->verifyAvailability($fileId, $sha256, $size) === 'verified';
    }

    public function verifyAvailability(string $fileId, string $sha256, int $size): string
    {
        $object = $this->objects[$fileId] ?? null;
        if (! $object) return 'unavailable';
        return strlen($object['bytes']) === $size
            && hash_equals($object['sha256'], $sha256)
            && hash_equals($sha256, hash('sha256', $object['bytes']))
            ? 'verified'
            : 'content_mismatch';
    }

    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $this->objects[$fileId] = ['bytes' => file_get_contents($localPath), 'sha256' => $sha256, 'folder_id' => $folderId];
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    public function retrieve(string $fileId)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) ($this->objects[$fileId]['bytes'] ?? ''));
        rewind($stream);
        return $stream;
    }
}

try {
    $payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, flags: JSON_THROW_ON_ERROR);
    $root = $payload['task_root'];
    $cacheRoot = $root.DIRECTORY_SEPARATOR.'cache';
    $storageRoot = $root.DIRECTORY_SEPARATOR.'storage';
    config([
        'cache.default' => 'file',
        'cache.stores.file.path' => $cacheRoot,
        'cache.stores.file.lock_path' => $cacheRoot,
        'filesystems.disks.local.root' => $storageRoot,
        'logging.default' => 'stderr',
        'services.google_drive_archive.enabled' => true,
        'services.document_archive.driver' => 'fake',
    ]);
    Cache::setDefaultDriver('file');
    Cache::purge('file');
    Storage::forgetDisk('local');
    $app->instance(GoogleDriveArchiveGateway::class, new DispatchInProgressDelayedFakeGateway($payload));

    $actor = User::query()->findOrFail((int) $payload['actor_id']);
    $source = 'bms';
    $action = (string) $payload['action'];
    $recordId = (int) $payload['record_id'];
    $request = Request::create('/submission-tracking/'.$source.'/'.$recordId.'/'.$action, 'POST', ['stage' => $action]);
    $request->headers->set('Referer', 'http://localhost/submission-tracking');
    $request->setUserResolver(fn () => $actor);
    $app->instance('request', $request);
    URL::setRequest($request);
    Auth::guard('web')->setUser($actor);

    $startedAt = hrtime(true);
    try {
        $response = $app->make(SubmissionTrackingController::class)->transition($request, $source, $recordId, $action);
        $result = ['result' => 'success', 'status' => $response->getStatusCode()];
    } catch (Illuminate\Validation\ValidationException $exception) {
        $errors = $exception->errors();
        $result = [
            'result' => 'validation',
            'status' => 422,
            'message' => (string) (array_values($errors)[0][0] ?? ''),
        ];
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        $result = ['result' => 'http_error', 'status' => $exception->getStatusCode()];
    }
    $result['duration_ms'] = (hrtime(true) - $startedAt) / 1_000_000;
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'result' => 'worker_error',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
