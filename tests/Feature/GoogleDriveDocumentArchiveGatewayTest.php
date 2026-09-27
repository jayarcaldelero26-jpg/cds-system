<?php

use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveException;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Archive\GoogleDriveDocumentArchiveGateway;
use App\Services\Archive\UnconfiguredGoogleDriveArchiveGateway;
use App\Models\BmsReportSubmission;
use App\Models\DocumentArchive;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function mockedDriveGateway(array $responses, array &$history): GoogleDriveDocumentArchiveGateway
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new GoogleDriveDocumentArchiveGateway(new Client(['handler' => $stack]));
}

function configureMockDrive(string $suffix = ''): void
{
    config([
        'services.google_drive_archive.enabled' => true,
        'services.document_archive.driver' => 'google-drive',
        'services.document_archive.client_id' => 'test-client-'.$suffix,
        'services.document_archive.client_secret' => 'synthetic-client-secret-'.$suffix,
        'services.document_archive.refresh_token' => 'synthetic-refresh-token-'.$suffix,
        'services.document_archive.folder_id' => 'test-root-folder-'.$suffix,
    ]);
}

function oauthMockResponse(string $token = 'synthetic-access-token'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => $token, 'expires_in' => 3600], JSON_THROW_ON_ERROR));
}

test('archive driver selection is explicit and invalid or unconfigured values fail closed', function (): void {
    config(['services.document_archive.driver' => 'fake']);
    app()->forgetInstance(GoogleDriveArchiveGateway::class);
    expect(app(GoogleDriveArchiveGateway::class))->toBeInstanceOf(FakeDocumentArchiveGateway::class);

    config(['services.document_archive.driver' => 'google-drive']);
    app()->forgetInstance(GoogleDriveArchiveGateway::class);
    expect(app(GoogleDriveArchiveGateway::class))->toBeInstanceOf(GoogleDriveDocumentArchiveGateway::class);

    config(['services.document_archive.driver' => 'invalid-driver']);
    app()->forgetInstance(GoogleDriveArchiveGateway::class);
    expect(app(GoogleDriveArchiveGateway::class))->toBeInstanceOf(UnconfiguredGoogleDriveArchiveGateway::class);
});

test('missing Google Drive config reports presence only and fails closed', function (): void {
    config(['services.document_archive.client_id' => null, 'services.document_archive.client_secret' => null,
        'services.document_archive.refresh_token' => null, 'services.document_archive.folder_id' => null]);
    $gateway = new GoogleDriveDocumentArchiveGateway(new Client(['handler' => HandlerStack::create(new MockHandler())]));

    expect($gateway->configurationStatus())->toBe(['client_id' => false, 'client_secret' => false, 'refresh_token' => false, 'folder_id' => false]);
    try {
        $gateway->validateRootFolder();
        test()->fail('Missing configuration must fail closed.');
    } catch (GoogleDriveArchiveException $exception) {
        expect($exception->getMessage())->toBe('Google Drive archive configuration is incomplete.');
    }
});

test('disabled archive configuration stops before any provider request', function (): void {
    config(['services.google_drive_archive.enabled' => false]);
    $history = [];
    $gateway = mockedDriveGateway([], $history);

    try {
        $gateway->validateRootFolder();
        test()->fail('Disabled archive integration must fail before provider access.');
    } catch (GoogleDriveArchiveException $exception) {
        expect($exception->getMessage())->toBe('Google Drive archive integration is disabled.')
            ->and($history)->toBe([]);
    }
});

test('OAuth refresh succeeds and configured root folder is validated by Drive ID', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['id' => config('services.document_archive.folder_id'), 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false], JSON_THROW_ON_ERROR)),
    ], $history);

    expect($gateway->validateRootFolder())->toBe(['status' => 200, 'valid' => true])
        ->and($history)->toHaveCount(2)
        ->and((string) $history[0]['request']->getUri())->toBe('https://oauth2.googleapis.com/token')
        ->and((string) $history[0]['request']->getBody())->toContain('grant_type=refresh_token')
        ->and((string) $history[1]['request']->getUri())->toContain('/drive/v3/files/test-root-folder-');
});

test('configured root must be an accessible folder and is never replaced automatically', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['id' => config('services.document_archive.folder_id'), 'mimeType' => 'application/vnd.google-apps.spreadsheet', 'trashed' => false], JSON_THROW_ON_ERROR)),
    ], $history);

    try {
        $gateway->validateRootFolder();
        test()->fail('A non-folder root must be rejected.');
    } catch (GoogleDriveArchiveException $exception) {
        expect($exception->getMessage())->toBe('Configured Google Drive root is not an accessible folder.')
            ->and($exception->httpStatus)->toBe(200);
    }
    expect($history)->toHaveCount(2);
});

test('expired access token is refreshed once after Drive returns unauthorized', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse('synthetic-first-token'),
        new Response(401, [], '{}'),
        oauthMockResponse('synthetic-refreshed-token'),
        new Response(200, [], json_encode(['id' => config('services.document_archive.folder_id'), 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false], JSON_THROW_ON_ERROR)),
    ], $history);

    expect($gateway->validateRootFolder())->toBe(['status' => 200, 'valid' => true])
        ->and($history)->toHaveCount(4)
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer synthetic-first-token')
        ->and($history[3]['request']->getHeaderLine('Authorization'))->toBe('Bearer synthetic-refreshed-token');
});

test('OAuth errors are sanitized and never echo client or refresh credentials', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $clientSecret = config('services.document_archive.client_secret');
    $refreshToken = config('services.document_archive.refresh_token');
    $accessToken = 'synthetic-access-token-in-error';
    $history = [];
    $gateway = mockedDriveGateway([
        new Response(400, [], json_encode(['error' => 'invalid_grant', 'diagnostic' => $clientSecret.' '.$refreshToken.' '.$accessToken], JSON_THROW_ON_ERROR)),
    ], $history);

    try {
        $gateway->validateRootFolder();
        test()->fail('OAuth failure must not continue to Drive.');
    } catch (GoogleDriveArchiveException $exception) {
        expect($exception->getMessage())->toBe('Google Drive OAuth token request failed.')
            ->and($exception->httpStatus)->toBe(400)
            ->and($exception->getMessage())->not->toContain($clientSecret)
            ->and($exception->getMessage())->not->toContain($refreshToken)
            ->and($exception->getMessage())->not->toContain($accessToken);
    }
    expect($history)->toHaveCount(1);
});

test('resumable upload resolves unit, office, and module folders and returns a Drive file ID', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    \Illuminate\Support\Facades\Cache::flush();
    $history = [];
    $responses = [oauthMockResponse()];
    foreach (['Conservation Unit', 'CENRO Mati', 'BMS'] as $index => $segment) {
        $responses[] = new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR));
        $responses[] = new Response(200, [], json_encode(['id' => 'folder-'.$index, 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR));
    }
    $responses[] = new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=synthetic-session']);
    $content = "%PDF-1.4\nmocked upload";
    // Resumable init only returns a session Location; final PUT returns the
    // Drive v3 file resource (here intentionally partial, with ID authoritative).
    $responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode(['kind' => 'drive#file', 'id' => 'drive-file-42'], JSON_THROW_ON_ERROR));
    $gateway = mockedDriveGateway($responses, $history);
    $tempPath = tempnam(sys_get_temp_dir(), 'cds-drive-unit-');
    file_put_contents($tempPath, $content);

    try {
    $result = $gateway->upload($tempPath, '2026-CDS-000001.pdf', ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'], hash('sha256', $content), config('services.document_archive.folder_id'), ['folder_path' => ['Conservation Unit', 'CENRO Mati', 'BMS']]);
    } finally {
        @unlink($tempPath);
    }

    expect($result)->toBe(['file_id' => 'drive-file-42', 'folder_id' => 'folder-2'])
        ->and(collect($history)->pluck('request')->filter(fn ($request) => str_contains((string) $request->getUri(), '/upload/drive/v3/files?upload_id='))->first()->getMethod())->toBe('PUT')
        ->and(collect($history)->first(fn ($entry) => str_contains((string) $entry['request']->getUri(), 'uploadType=resumable'))['request']->getUri()->getQuery())->toContain('id%2Cname%2CmimeType%2Csize%2Cparents%2Cmd5Checksum%2CappProperties')
        ->and(urldecode(collect($history)->pluck('request')->filter(fn ($request) => $request->getUri()->getPath() === '/drive/v3/files')->map(fn ($request) => $request->getUri()->getQuery())->implode('|')))->toContain('Conservation Unit')
        ->and(urldecode(collect($history)->pluck('request')->filter(fn ($request) => $request->getUri()->getPath() === '/drive/v3/files')->map(fn ($request) => $request->getUri()->getQuery())->implode('|')))->toContain('BMS')
        ->and(collect($history)->pluck('request')->filter(fn ($request) => $request->getUri()->getPath() === '/drive/v3/files')->count())->toBe(6);
});

test('archive folder identifiers are cached so subsequent writes skip folder lookups', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    \Illuminate\Support\Facades\Cache::flush();
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['id' => 'cached-unit-folder', 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['id' => 'cached-office-folder', 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['id' => 'cached-module-folder', 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=first-cache-session']),
        new Response(200, [], json_encode(['id' => 'first-cached-file', 'parents' => ['cached-module-folder']], JSON_THROW_ON_ERROR)),
        new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=second-cache-session']),
        new Response(200, [], json_encode(['id' => 'second-cached-file', 'parents' => ['cached-module-folder']], JSON_THROW_ON_ERROR)),
    ], $history);
    $path = tempnam(sys_get_temp_dir(), 'cds-drive-folder-cache-');
    $content = "%PDF-1.4\nfolder cache";
    file_put_contents($path, $content);
    $identity = ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'];
    $context = ['folder_path' => ['Conservation Unit', 'CENRO Mati', 'BMS']];

    try {
        $first = $gateway->upload($path, '2026-CDS-000001.pdf', $identity, hash('sha256', $content), config('services.document_archive.folder_id'), $context);
        $second = $gateway->upload($path, '2026-CDS-000002.pdf', $identity, hash('sha256', $content), config('services.document_archive.folder_id'), $context);
    } finally {
        @unlink($path);
    }

    $folderRequests = collect($history)->pluck('request')->filter(fn ($request) => $request->getUri()->getPath() === '/drive/v3/files');
    expect($first['folder_id'])->toBe('cached-module-folder')
        ->and($second['folder_id'])->toBe('cached-module-folder')
        ->and($history)->toHaveCount(11)
        ->and($folderRequests)->toHaveCount(6)
        ->and(array_values($folderRequests->map(fn ($request) => $request->getMethod())->all()))->toBe(['GET', 'POST', 'GET', 'POST', 'GET', 'POST']);
});

test('stale cached folder IDs are re-resolved after an upload session is rejected', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    \Illuminate\Support\Facades\Cache::flush();
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['id' => 'old-unit-folder', 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['id' => 'old-office-folder', 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['id' => 'old-module-folder', 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=initial-stale-session']),
        new Response(200, [], json_encode(['id' => 'initial-stale-file', 'parents' => ['old-module-folder']], JSON_THROW_ON_ERROR)),
        new Response(404, [], '{}'),
        new Response(200, [], json_encode(['files' => [['id' => 'fresh-unit-folder', 'mimeType' => 'application/vnd.google-apps.folder']]], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => [['id' => 'fresh-office-folder', 'mimeType' => 'application/vnd.google-apps.folder']]], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => [['id' => 'fresh-module-folder', 'mimeType' => 'application/vnd.google-apps.folder']]], JSON_THROW_ON_ERROR)),
        new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=fresh-session']),
        new Response(200, [], json_encode(['id' => 'fresh-file', 'parents' => ['fresh-module-folder']], JSON_THROW_ON_ERROR)),
    ], $history);
    $path = tempnam(sys_get_temp_dir(), 'cds-drive-folder-stale-');
    $content = "%PDF-1.4\nstale cache";
    file_put_contents($path, $content);
    $identity = ['source_type' => 'bms', 'source_id' => 43, 'logical_slot' => 'mov'];
    $context = ['folder_path' => ['Conservation Unit', 'CENRO Mati', 'BMS']];

    try {
        $gateway->upload($path, '2026-CDS-000001.pdf', $identity, hash('sha256', $content), config('services.document_archive.folder_id'), $context);
        $retried = $gateway->upload($path, '2026-CDS-000002.pdf', $identity, hash('sha256', $content), config('services.document_archive.folder_id'), $context);
    } finally {
        @unlink($path);
    }

    expect($retried)->toBe(['file_id' => 'fresh-file', 'folder_id' => 'fresh-module-folder'])
        ->and($history)->toHaveCount(15);
});

test('resumable final response diagnostics expose shape only and partial Drive metadata is accepted', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    \Illuminate\Support\Facades\Cache::flush();
    $history = [];
    $responses = [oauthMockResponse()];
    foreach (['Conservation Unit', 'CENRO Mati', 'BMS'] as $index => $segment) {
        $responses[] = new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR));
        $responses[] = new Response(200, [], json_encode(['id' => 'diag-folder-'.$index, 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR));
    }
    $responses[] = new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=diag-session', 'Content-Type' => 'application/json']);
    $responses[] = new Response(200, ['Content-Type' => 'application/json', 'X-GUploader-UploadID' => 'synthetic-request-id'], json_encode(['kind' => 'drive#file', 'id' => 'partial-file-id'], JSON_THROW_ON_ERROR));
    $gateway = mockedDriveGateway($responses, $history);
    $tempPath = tempnam(sys_get_temp_dir(), 'cds-drive-partial-');
    $content = 'text body for partial response';
    file_put_contents($tempPath, $content);
    try {
        expect($gateway->upload($tempPath, '2026-CDS-000001.pdf', ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'], hash('sha256', $content), config('services.document_archive.folder_id'), ['folder_path' => ['Conservation Unit', 'CENRO Mati', 'BMS']]))
            ->toBe(['file_id' => 'partial-file-id', 'folder_id' => 'diag-folder-2']);
    } finally {
        @unlink($tempPath);
    }
    $diagnostics = $gateway->lastUploadDiagnostics();
    expect($diagnostics)->toHaveCount(2)
        ->and($diagnostics[0])->toMatchArray(['phase' => 'UPLOAD_INIT', 'mode' => 'resumable', 'http_status' => 200, 'location_present' => true, 'id_present' => false])
        ->and($diagnostics[1])->toMatchArray(['phase' => 'UPLOAD_BODY', 'http_status' => 200, 'json_top_level_keys' => ['kind', 'id'], 'id_present' => true, 'location_present' => false, 'request_error_id' => 'synthetic-request-id'])
        ->and($diagnostics[1]['response_body_length'])->toBeGreaterThan(0);
});

test('malformed successful final upload response records parse phase and safe shape', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $responses = [oauthMockResponse()];
    foreach (range(0, 2) as $index) {
        $responses[] = new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR));
        $responses[] = new Response(200, [], json_encode(['id' => 'malformed-folder-'.$index, 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR));
    }
    $responses[] = new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=malformed-session']);
    $responses[] = new Response(200, ['Content-Type' => 'text/plain'], 'created-but-not-json');
    $history = [];
    $gateway = mockedDriveGateway($responses, $history);
    $tempPath = tempnam(sys_get_temp_dir(), 'cds-drive-malformed-');
    $content = 'malformed response payload';
    file_put_contents($tempPath, $content);
    try {
        $gateway->upload($tempPath, 'Final MOV.pdf', ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'], hash('sha256', $content), config('services.document_archive.folder_id'), ['folder_path' => ['Conservation Unit', 'CENRO Mati', 'BMS']]);
        test()->fail('Malformed successful metadata must require reconciliation.');
    } catch (GoogleDriveArchiveException $exception) {
        expect($exception->httpStatus)->toBe(200)->and($exception->getMessage())->toBe('Google Drive returned invalid metadata.');
    } finally {
        @unlink($tempPath);
    }
    expect($gateway->lastUploadDiagnostics()[1])->toMatchArray([
        'phase' => 'UPLOAD_BODY', 'http_status' => 200, 'content_type' => 'text/plain',
        'json_top_level_keys' => [], 'id_present' => false, 'location_present' => false,
    ]);
});

test('Drive upload failure is sanitized and does not report a remote archive identity', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $responses = [oauthMockResponse()];
    foreach (range(0, 2) as $index) {
        $responses[] = new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR));
        $responses[] = new Response(200, [], json_encode(['id' => 'upload-folder-'.$index, 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR));
    }
    $responses[] = new Response(403, ['Content-Type' => 'application/json', 'X-Goog-Request-Id' => 'synthetic-drive-error-id'], json_encode(['error' => ['message' => 'synthetic secret must not leak']], JSON_THROW_ON_ERROR));
    $history = [];
    $gateway = mockedDriveGateway($responses, $history);
    $tempPath = tempnam(sys_get_temp_dir(), 'cds-drive-fail-');
    $content = '%PDF-1.4 upload-failure';
    file_put_contents($tempPath, $content);

    try {
        $gateway->upload($tempPath, 'Final MOV.pdf', ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'], hash('sha256', $content), config('services.document_archive.folder_id'), ['folder_path' => ['Conservation Unit', 'CENRO Mati', 'BMS']]);
        test()->fail('The denied upload must throw.');
    } catch (GoogleDriveArchiveException $exception) {
        expect($exception->getMessage())->toBe('Google Drive API request failed.')
            ->and($exception->httpStatus)->toBe(403)
            ->and($exception->getMessage())->not->toContain('synthetic secret');
    } finally {
        @unlink($tempPath);
    }
    expect($history)->toHaveCount(8)
        ->and($gateway->lastUploadDiagnostics()[0])->toMatchArray([
            'phase' => 'UPLOAD_INIT', 'mode' => 'resumable', 'http_status' => 403,
            'content_type' => 'application/json', 'json_top_level_keys' => ['error'],
            'id_present' => false, 'location_present' => false, 'request_error_id' => 'synthetic-drive-error-id',
        ]);
});

test('ambiguous connectivity upload can reconcile only its exact hash and marked test item', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $sha256 = hash('sha256', 'one-run-connectivity-content');
    $startedAt = now()->utc()->toIso8601ZuluString();
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['files' => [[
            'id' => 'exact-connectivity-test-file',
            'name' => 'CDS-SMART-GDRIVE-CONNECTION-TEST-random.txt',
            'createdTime' => now()->utc()->addSecond()->toIso8601ZuluString(),
            'parents' => [config('services.document_archive.folder_id')],
            'appProperties' => ['cds_connectivity_test' => 'true', 'cds_sha256' => $sha256, 'cds_run_started_at' => $startedAt],
        ]]], JSON_THROW_ON_ERROR)),
    ], $history);
    $method = (new ReflectionClass($gateway))->getMethod('findConnectivityTestFiles');
    $method->setAccessible(true);

    expect($method->invoke($gateway, $sha256, $startedAt))->toBe(['exact-connectivity-test-file'])
        ->and($history)->toHaveCount(2)
        ->and($history[1]['request']->getUri()->getQuery())->toContain($sha256)
        ->and($history[1]['request']->getUri()->getQuery())->toContain('cds_connectivity_test')
        ->and($history[1]['request']->getUri()->getQuery())->toContain('createdTime');
});

test('ambiguous connectivity reconciliation fails closed on zero or multiple exact matches', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $sha256 = hash('sha256', 'one-run-connectivity-content');
    $startedAt = now()->utc()->toIso8601ZuluString();
    $validFile = [
        'id' => 'matching-test-file',
        'name' => 'CDS-SMART-GDRIVE-CONNECTION-TEST-random.txt',
        'createdTime' => now()->utc()->addSecond()->toIso8601ZuluString(),
        'parents' => [config('services.document_archive.folder_id')],
        'appProperties' => ['cds_connectivity_test' => 'true', 'cds_sha256' => $sha256, 'cds_run_started_at' => $startedAt],
    ];
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['files' => [$validFile, [...$validFile, 'id' => 'second-match']]], JSON_THROW_ON_ERROR)),
    ], $history);
    $method = (new ReflectionClass($gateway))->getMethod('findConnectivityTestFiles');
    $method->setAccessible(true);

    expect($method->invoke($gateway, $sha256, $startedAt))->toBe([])
        ->and($method->invoke($gateway, $sha256, $startedAt))->toBe(['matching-test-file', 'second-match']);
});

test('ambiguous successful resumable response uniquely reconciles then verifies downloads and cleans up', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $metadata = null;
    $uploadedBytes = null;
    $history = [];
    $responses = [
        oauthMockResponse(),
        new Response(200, [], json_encode(['id' => config('services.document_archive.folder_id'), 'mimeType' => 'application/vnd.google-apps.folder'], JSON_THROW_ON_ERROR)),
        function ($request) use (&$metadata) {
            $metadata = json_decode((string) $request->getBody(), true);
            return new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=ambiguous-session']);
        },
        function ($request) use (&$uploadedBytes) {
            $bodyResource = $request->getBody()->detach();
            $uploadedBytes = is_resource($bodyResource) ? stream_get_contents($bodyResource) : '';
            if (is_resource($bodyResource)) fclose($bodyResource);
            return new Response(200, ['Content-Type' => 'text/plain', 'X-GUploader-UploadID' => 'synthetic-google-request-id'], 'malformed final response');
        },
        function () use (&$metadata, &$uploadedBytes) {
            $properties = $metadata['appProperties'];
            return new Response(200, [], json_encode(['files' => [[
                'id' => 'reconciled-connectivity-id',
                'name' => $metadata['name'],
                'createdTime' => \Illuminate\Support\Carbon::parse($properties['cds_run_started_at'])->addSecond()->toIso8601ZuluString(),
                'parents' => $metadata['parents'],
                'appProperties' => $properties,
            ]]], JSON_THROW_ON_ERROR));
        },
        function () use (&$uploadedBytes) {
            return new Response(200, [], json_encode(['id' => 'reconciled-connectivity-id', 'size' => (string) strlen($uploadedBytes), 'trashed' => false], JSON_THROW_ON_ERROR));
        },
        function () use (&$uploadedBytes) { return new Response(200, [], $uploadedBytes); },
        function () use (&$uploadedBytes) { return new Response(200, [], $uploadedBytes); },
        new Response(204),
        new Response(404, [], '{}'),
    ];
    $gateway = mockedDriveGateway($responses, $history);

    $result = $gateway->connectivityCheck();

    expect($result['oauth'])->toBeTrue()
        ->and($result['folder'])->toBeTrue()
        ->and($result['upload_mode'])->toBe('resumable')
        ->and($result['upload'])->toBeTrue()
        ->and($result['file_id'])->toBe('reconciled-connectivity-id')
        ->and($result['reconciliation_matches'])->toBe(1)
        ->and($result['verify'])->toBeTrue()
        ->and($result['download'])->toBeTrue()
        ->and($result['sha256'])->toBeTrue()
        ->and($result['content_match'])->toBeTrue()
        ->and($result['cleanup'])->toBeTrue()
        ->and($result['operation_phase'])->toBe('RESPONSE_PARSE')
        ->and($result['upload_responses'][1]['http_status'])->toBe(200)
        ->and($result['upload_responses'][1]['id_present'])->toBeFalse()
        ->and($result['upload_responses'][1]['request_error_id'])->toBe('synthetic-google-request-id')
        ->and($history)->toHaveCount(10);
});

test('Drive verification downloads content and rejects checksum mismatches', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $content = "%PDF-1.4\nverified bytes";
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['id' => 'drive-file-verified', 'size' => (string) strlen($content), 'trashed' => false], JSON_THROW_ON_ERROR)),
        new Response(200, [], $content),
    ], $history);

    expect($gateway->verifyAvailability('drive-file-verified', hash('sha256', $content), strlen($content)))->toBe('verified')
        ->and($history[2]['request']->getUri()->getQuery())->toBe('alt=media');

    configureMockDrive(bin2hex(random_bytes(4)));
    $mismatchHistory = [];
    $mismatch = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['id' => 'drive-file-verified', 'size' => (string) strlen($content), 'trashed' => false], JSON_THROW_ON_ERROR)),
        new Response(200, [], $content),
    ], $mismatchHistory);
    expect($mismatch->verifyAvailability('drive-file-verified', str_repeat('0', 64), strlen($content)))->toBe('content_mismatch');

    configureMockDrive(bin2hex(random_bytes(4)));
    $notFoundHistory = [];
    $notFound = mockedDriveGateway([oauthMockResponse(), new Response(404, [], '{}')], $notFoundHistory);
    expect($notFound->verifyAvailability('missing-drive-file', hash('sha256', $content), strlen($content)))->toBe('unavailable');

    configureMockDrive(bin2hex(random_bytes(4)));
    $providerFailureHistory = [];
    $providerFailure = mockedDriveGateway([oauthMockResponse(), new Response(503, [], '{}')], $providerFailureHistory);
    expect($providerFailure->verifyAvailability('temporarily-unavailable-drive-file', hash('sha256', $content), strlen($content)))->toBe('unknown');
});

test('archive identity and SHA-256 query reuses existing remote file metadata', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], json_encode(['files' => [['id' => 'existing-drive-file', 'parents' => ['record-folder']]]], JSON_THROW_ON_ERROR)),
    ], $history);
    $identity = ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'];
    $found = $gateway->findByIdentityAndHash($identity, str_repeat('a', 64));

    expect($found)->toBe(['file_id' => 'existing-drive-file', 'folder_id' => 'record-folder'])
        ->and($history[1]['request']->getUri()->getQuery())->toContain('cds_identity')
        ->and($history[1]['request']->getUri()->getQuery())->toContain(str_repeat('a', 64));
});

test('rearchive updates the same Drive file ID with a resumable PATCH upload', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files/archive-id?upload_id=replace-session']),
        new Response(200, [], json_encode(['id' => 'archive-id', 'size' => '17', 'parents' => ['existing-folder']], JSON_THROW_ON_ERROR)),
    ], $history);
    $tempPath = tempnam(sys_get_temp_dir(), 'cds-drive-replace-');
    $bytes = '%PDF-1.4 update';
    file_put_contents($tempPath, $bytes);

    try {
        $result = $gateway->replace('archive-id', $tempPath, 'Final MOV.pdf', ['source_type' => 'bms', 'source_id' => 42, 'logical_slot' => 'mov'], hash('sha256', $bytes), 'existing-folder');
    } finally {
        @unlink($tempPath);
    }

    expect($result)->toBe(['file_id' => 'archive-id', 'folder_id' => 'existing-folder'])
        ->and($history[1]['request']->getMethod())->toBe('PATCH')
        ->and($history[2]['request']->getMethod())->toBe('PUT');
});

test('authorized protected attachment route retrieves archived bytes through the real adapter boundary', function (): void {
    configureMockDrive(bin2hex(random_bytes(4)));
    $actor = User::factory()->create([
        'section' => \App\Services\Authorization\OrganizationalAccessService::CENRO_FOCAL,
        'office_designated' => 'CENRO Mati',
        'unit_assignment' => \App\Services\Authorization\OrganizationalAccessService::CONSERVATION,
    ]);
    $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bms.view', 'web'));
    $bytes = "%PDF-1.4\nreal-adapter-mocked-retrieval";
    $path = 'bms-report-movs/archived-test.pdf';
    $record = BmsReportSubmission::query()->create([
        'target_office' => 'CENRO Mati', 'semester' => '1st Semester', 'created_by' => $actor->id,
        'updated_by' => $actor->id, 'mov_file_path' => $path, 'mov_file_name' => 'Archived MOV.pdf',
    ]);
    DocumentArchive::query()->create([
        'source_type' => 'bms', 'source_id' => $record->id, 'logical_slot' => 'mov',
        'google_drive_file_id' => 'private-drive-object-1', 'google_drive_folder_id' => 'private-folder-1',
        'archived_sha256' => hash('sha256', $bytes), 'archived_size' => strlen($bytes),
        'archived_at' => now(), 'archived_by' => $actor->id, 'archive_status' => 'ARCHIVED',
        'original_filename' => 'Archived MOV.pdf',
    ]);
    $history = [];
    $gateway = mockedDriveGateway([
        oauthMockResponse(),
        new Response(200, [], $bytes),
    ], $history);
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $response = $this->actingAs($actor)->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']));
    expect($history)->toHaveCount(2)
        ->and(collect($history)->pluck('request')->filter(fn ($request) => $request->getUri()->getHost() === 'www.googleapis.com')->every(fn ($request) => $request->getMethod() === 'GET'))->toBeTrue()
        ->and($history[1]['request']->getUri()->getQuery())->toBe('alt=media');
    $response->assertOk()->assertStreamedContent($bytes);
});

test('authorized missing current document fails safely when its archived object is unavailable', function (): void {
    config(['services.document_archive.driver' => 'fake']);
    $actor = User::factory()->create([
        'section' => \App\Services\Authorization\OrganizationalAccessService::CENRO_FOCAL,
        'office_designated' => 'CENRO Mati',
        'unit_assignment' => \App\Services\Authorization\OrganizationalAccessService::CONSERVATION,
    ]);
    $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bms.view', 'web'));
    $record = BmsReportSubmission::query()->create([
        'target_office' => 'CENRO Mati', 'semester' => '1st Semester', 'created_by' => $actor->id,
        'updated_by' => $actor->id, 'mov_file_path' => 'current-documents/bms/missing/current.pdf',
        'mov_file_name' => 'Missing.pdf',
    ]);
    DocumentArchive::query()->create([
        'source_type' => 'bms', 'source_id' => $record->id, 'logical_slot' => 'mov',
        'google_drive_file_id' => 'unavailable-drive-object', 'archived_sha256' => hash('sha256', 'expected'),
        'archived_size' => 8, 'archive_status' => 'ARCHIVED', 'original_filename' => 'Missing.pdf',
    ]);
    $gateway = Mockery::mock(GoogleDriveArchiveGateway::class);
    $gateway->shouldReceive('retrieve')->once()->with('unavailable-drive-object')->andThrow(new \App\Services\Archive\GoogleDriveArchiveException('Object not found.', 404));
    $gateway->shouldNotReceive('upload');
    $gateway->shouldNotReceive('replace');
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);

    $this->actingAs($actor)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertNotFound();
    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertNotFound();
    expect(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->value('remote_availability'))->toBe('unavailable');
});

test('authorized missing current document with no archive fails without creating or deleting files', function (): void {
    config(['services.document_archive.driver' => 'fake']);
    \Illuminate\Support\Facades\Storage::fake('local');
    $actor = User::factory()->create([
        'section' => \App\Services\Authorization\OrganizationalAccessService::CENRO_FOCAL,
        'office_designated' => 'CENRO Mati',
        'unit_assignment' => \App\Services\Authorization\OrganizationalAccessService::CONSERVATION,
    ]);
    $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bms.view', 'web'));
    $record = BmsReportSubmission::query()->create([
        'target_office' => 'CENRO Mati', 'semester' => '1st Semester', 'created_by' => $actor->id,
        'updated_by' => $actor->id, 'mov_file_path' => 'current-documents/bms/missing/no-archive.pdf',
        'mov_file_name' => 'No archive.pdf',
    ]);
    $before = \Illuminate\Support\Facades\Storage::disk('local')->allFiles();

    $this->actingAs($actor)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertNotFound();

    expect(\Illuminate\Support\Facades\Storage::disk('local')->allFiles())->toBe($before);
});
