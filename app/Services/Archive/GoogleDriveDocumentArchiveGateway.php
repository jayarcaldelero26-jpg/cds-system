<?php

namespace App\Services\Archive;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Private My Drive adapter. Drive IDs and private appProperties are the archive identities. */
class GoogleDriveDocumentArchiveGateway implements GoogleDriveArchiveGateway
{
    private const API = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
    private const OAUTH = 'https://oauth2.googleapis.com/token';
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private ClientInterface $client;
    private string $uploadPhase = '';
    private array $uploadDiagnostics = [];

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client(['connect_timeout' => 10, 'timeout' => 120]);
    }

    /** Configuration health only; secret values are never returned. */
    public function configurationStatus(): array
    {
        $config = config('services.document_archive', []);
        return [
            'client_id' => filled($config['client_id'] ?? null),
            'client_secret' => filled($config['client_secret'] ?? null),
            'refresh_token' => filled($config['refresh_token'] ?? null),
            'folder_id' => filled($config['folder_id'] ?? null),
        ];
    }

    /** Read-only authenticated account quota; no file operation is performed. */
    public function storageQuota(): array
    {
        $response = $this->driveRequest('GET', self::API.'/about', [
            'query' => ['fields' => 'storageQuota(limit,usage,usageInDrive,usageInDriveTrash)'],
        ]);

        return $this->json($response)['storageQuota'] ?? [];
    }

    /** Sanitized metadata from the most recent resumable upload responses. */
    public function lastUploadDiagnostics(): array
    {
        return $this->uploadDiagnostics;
    }

    /** Validates the configured root without creating or changing anything. */
    public function validateRootFolder(): array
    {
        $folderId = $this->requiredConfig('folder_id');
        $response = $this->driveRequest('GET', self::API.'/files/'.rawurlencode($folderId), [
            'query' => ['fields' => 'id,mimeType,trashed'],
        ]);
        $folder = $this->json($response);
        if (($folder['id'] ?? null) !== $folderId || ($folder['mimeType'] ?? null) !== self::FOLDER_MIME || ($folder['trashed'] ?? false)) {
            throw new GoogleDriveArchiveException('Configured Google Drive root is not an accessible folder.', 200);
        }
        return ['status' => 200, 'valid' => true];
    }

    public function findByIdentityAndHash(array $identity, string $sha256): ?array
    {
        $identityHash = $this->identityHash($identity);
        $query = "appProperties has { key='cds_identity' and value='{$identityHash}' } and appProperties has { key='cds_sha256' and value='{$sha256}' } and trashed = false";
        $response = $this->driveRequest('GET', self::API.'/files', [
            'query' => ['q' => $query, 'pageSize' => 100, 'fields' => 'files(id,name,size,parents,appProperties)'],
        ]);
        $files = $this->json($response)['files'] ?? [];
        if (! is_array($files) || $files === []) return null;
        $file = $files[0];
        return is_string($file['id'] ?? null)
            ? ['file_id' => $file['id'], 'folder_id' => $file['parents'][0] ?? null]
            : null;
    }

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $this->assertLocalContent($localPath, $sha256);
        $parent = $this->folderForIdentity($folderId, $archiveContext);
        $metadata = $this->fileMetadata($filename, $parent, $identity, $sha256, 'application/pdf');
        try {
            return $this->uploadFile($localPath, $metadata, null, 'application/pdf');
        } catch (GoogleDriveArchiveException $exception) {
            // A cached Drive folder can be deleted or trashed between writes.
            // Only a rejected session-init is safe to retry: no upload body has
            // been sent, so this cannot create a duplicate remote document.
            if ($exception->httpStatus !== 404 || $this->uploadPhase !== 'UPLOAD_INIT') throw $exception;
            $parent = $this->folderForIdentity($folderId, $archiveContext, true);
            $metadata = $this->fileMetadata($filename, $parent, $identity, $sha256, 'application/pdf');
            return $this->uploadFile($localPath, $metadata, null, 'application/pdf');
        }
    }

    public function verify(string $fileId, string $sha256, int $size): bool
    {
        return $this->verifyAvailability($fileId, $sha256, $size) === 'verified';
    }

    public function verifyAvailability(string $fileId, string $sha256, int $size): string
    {
        $stream = null;
        try {
            $metadata = $this->json($this->driveRequest('GET', self::API.'/files/'.rawurlencode($fileId), [
                'query' => ['fields' => 'id,size,trashed,appProperties'],
            ]));
            if (($metadata['id'] ?? null) !== $fileId || ($metadata['trashed'] ?? false)
                || (int) ($metadata['size'] ?? -1) !== $size) return 'content_mismatch';

            $stream = $this->retrieve($fileId);
            if (! is_resource($stream)) return 'unknown';
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);
            return $bytes === $size && hash_equals($sha256, hash_final($context)) ? 'verified' : 'content_mismatch';
        } catch (GoogleDriveArchiveException $exception) {
            return $exception->httpStatus === 404 ? 'unavailable' : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $this->assertLocalContent($localPath, $sha256);
        $metadata = $this->fileMetadata($filename, null, $identity, $sha256, 'application/pdf');
        return $this->uploadFile($localPath, $metadata, $fileId, 'application/pdf');
    }

    /** Returns a readable PHP stream; caller must authorize before invoking. */
    public function retrieve(string $fileId)
    {
        $response = $this->driveRequest('GET', self::API.'/files/'.rawurlencode($fileId), [
            'query' => ['alt' => 'media'],
            'stream' => true,
        ]);
        $stream = $response->getBody()->detach();
        if (is_resource($stream)) return $stream;

        $fallback = fopen('php://temp', 'w+b');
        if (! is_resource($fallback)) throw new GoogleDriveArchiveException('Google Drive download could not be opened.');
        fwrite($fallback, (string) $response->getBody());
        rewind($fallback);
        return $fallback;
    }

    /** One harmless, self-cleaning connectivity check. It never uses a report document. */
    public function connectivityCheck(): array
    {
        $result = [
            'configuration' => $this->configurationStatus(),
            'oauth' => false, 'oauth_status' => null,
            'folder' => false, 'folder_status' => null,
            'upload' => false, 'verify' => false, 'download' => false,
            'sha256' => false, 'cleanup' => false, 'file_id' => null,
            'operation_phase' => null, 'operation_status' => null,
            'upload_mode' => 'resumable', 'upload_responses' => [],
            'reconciliation_matches' => null, 'phase_history' => ['AUTH'],
        ];
        $tempPath = null;
        $fileId = null;
        $phase = 'oauth';
        $startedAt = now()->utc();
        $startedAtIso = $startedAt->toIso8601ZuluString();
        $content = 'CDS-SMART Google Drive connectivity check; no report data. '.bin2hex(random_bytes(12));
        $sha256 = hash('sha256', $content);

        try {
            $this->accessToken();
            $result['oauth'] = true;
            $result['oauth_status'] = 200;
            $result['phase_history'][] = 'ROOT_LOOKUP';
        } catch (GoogleDriveArchiveException $exception) {
            $result['oauth_status'] = $exception->httpStatus ?: null;
            return $result;
        }

        try {
            $this->validateRootFolder();
            $result['folder'] = true;
            $result['folder_status'] = 200;
        } catch (GoogleDriveArchiveException $exception) {
            $result['folder_status'] = $exception->httpStatus ?: null;
            return $result;
        }

        $phase = 'UPLOAD_INIT';
        $result['phase_history'][] = 'UPLOAD_INIT';
        try {
            $tempPath = tempnam(sys_get_temp_dir(), 'cds-drive-check-');
            if (! is_string($tempPath) || file_put_contents($tempPath, $content) !== strlen($content)) {
                throw new GoogleDriveArchiveException('Temporary connectivity file could not be prepared.');
            }
            $root = $this->requiredConfig('folder_id');
            $metadata = [
                'name' => 'CDS-SMART-GDRIVE-CONNECTION-TEST-'.bin2hex(random_bytes(6)).'.txt',
                'mimeType' => 'text/plain',
                'parents' => [$root],
                'appProperties' => [
                    'cds_connectivity_test' => 'true',
                    'cds_sha256' => $sha256,
                    'cds_run_started_at' => $startedAtIso,
                ],
            ];
            try {
                $uploaded = $this->uploadFile($tempPath, $metadata, null, 'text/plain');
                $fileId = $uploaded['file_id'];
                $result['upload'] = true;
            } catch (GoogleDriveArchiveException $exception) {
                $result['operation_phase'] = $this->uploadPhase ?: $phase;
                $result['operation_status'] = $exception->httpStatus ?: null;
                foreach ($this->uploadDiagnostics as $diagnostic) {
                    $responsePhase = $diagnostic['phase'] ?? null;
                    if (is_string($responsePhase) && ! in_array($responsePhase, $result['phase_history'], true)) {
                        $result['phase_history'][] = $responsePhase;
                    }
                }
                if ($this->uploadPhase === 'RESPONSE_PARSE') $result['phase_history'][] = 'RESPONSE_PARSE';
                $phase = 'RECONCILIATION';
                $result['phase_history'][] = 'RECONCILIATION';
                $matches = $this->findConnectivityTestFiles($sha256, $startedAtIso);
                $result['reconciliation_matches'] = count($matches);
                if (count($matches) === 1) {
                    $fileId = $matches[0];
                    $result['upload'] = true;
                }
            }

            foreach ($this->uploadDiagnostics as $diagnostic) {
                $responsePhase = $diagnostic['phase'] ?? null;
                if (is_string($responsePhase) && ! in_array($responsePhase, $result['phase_history'], true)) {
                    $result['phase_history'][] = $responsePhase;
                }
            }
            if ($this->uploadPhase === 'RESPONSE_PARSE' && ! in_array('RESPONSE_PARSE', $result['phase_history'], true)) {
                $result['phase_history'][] = 'RESPONSE_PARSE';
            }

            $result['upload_responses'] = $this->uploadDiagnostics;
            if (is_string($fileId)) {
                $result['file_id'] = $fileId;
                $phase = 'VERIFY';
                $result['phase_history'][] = 'VERIFY';
                $result['verify'] = $this->verify($fileId, $sha256, strlen($content));
                if (! $result['verify']) $result['operation_phase'] = 'VERIFY';
                $phase = 'DOWNLOAD';
                $result['phase_history'][] = 'DOWNLOAD';
                $stream = $this->retrieve($fileId);
                $downloaded = stream_get_contents($stream);
                fclose($stream);
                $result['download'] = is_string($downloaded);
                $result['sha256'] = is_string($downloaded) && hash_equals($sha256, hash('sha256', $downloaded));
                $result['content_match'] = $downloaded === $content;
            } elseif ($result['operation_phase'] === null) {
                $result['operation_phase'] = 'RECONCILIATION';
            }
            $result['upload_responses'] = $this->uploadDiagnostics;
        } catch (GoogleDriveArchiveException $exception) {
            $result['operation_phase'] = $phase;
            $result['operation_status'] = $exception->httpStatus ?: null;
            $result['upload_responses'] = $this->uploadDiagnostics;
        } catch (Throwable) {
            $result['operation_phase'] = $phase;
            $result['operation_status'] = null;
            $result['upload_responses'] = $this->uploadDiagnostics;
        } finally {
            if (is_string($fileId)) {
                $result['phase_history'][] = 'CLEANUP';
                $cleanupPhase = $result['operation_phase'];
                $result['cleanup'] = $this->deleteConnectivityTestFile($fileId);
                if (! $result['cleanup'] && $cleanupPhase === null) $result['operation_phase'] = 'CLEANUP';
            }
            if (is_string($tempPath) && is_file($tempPath)) @unlink($tempPath);
        }

        return $result;
    }

    private function deleteConnectivityTestFile(string $fileId): bool
    {
        try {
            $response = $this->driveRequest('DELETE', self::API.'/files/'.rawurlencode($fileId));
            if (! in_array($response->getStatusCode(), [200, 204], true)) return false;
            try {
                $this->driveRequest('GET', self::API.'/files/'.rawurlencode($fileId), ['query' => ['fields' => 'id']]);
                return false;
            } catch (GoogleDriveArchiveException $exception) {
                return $exception->httpStatus === 404;
            }
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function findConnectivityTestFiles(string $sha256, string $startedAtIso): array
    {
        try {
            $root = $this->requiredConfig('folder_id');
            $query = "'{$root}' in parents and appProperties has { key='cds_connectivity_test' and value='true' } and appProperties has { key='cds_sha256' and value='{$sha256}' } and createdTime > '{$startedAtIso}' and trashed = false";
            $listing = $this->json($this->driveRequest('GET', self::API.'/files', [
                'query' => ['q' => $query, 'pageSize' => 100, 'fields' => 'nextPageToken,files(id,name,createdTime,parents,appProperties)'],
            ]));
            if (filled($listing['nextPageToken'] ?? null)) return [];
            $files = $listing['files'] ?? [];
            if (! is_array($files)) return [];
            $matches = [];
            foreach ($files as $file) {
                $createdTime = is_string($file['createdTime'] ?? null) ? strtotime($file['createdTime']) : false;
                $startedAt = strtotime($startedAtIso);
                if (is_string($file['id'] ?? null)
                    && str_starts_with((string) ($file['name'] ?? ''), 'CDS-SMART-GDRIVE-CONNECTION-TEST-')
                    && ($file['appProperties']['cds_connectivity_test'] ?? null) === 'true'
                    && ($file['appProperties']['cds_sha256'] ?? null) === $sha256
                    && ($file['appProperties']['cds_run_started_at'] ?? null) === $startedAtIso
                    && $createdTime !== false && $startedAt !== false && $createdTime >= $startedAt
                    && in_array($root, $file['parents'] ?? [], true)) {
                    $matches[] = $file['id'];
                }
            }
            return $matches;
        } catch (Throwable) {
            return [];
        }
    }

    private function uploadFile(string $localPath, array $metadata, ?string $fileId, string $mime): array
    {
        $this->uploadDiagnostics = [];
        $size = filesize($localPath);
        if (! is_int($size) || $size < 1) throw new GoogleDriveArchiveException('Archive file is empty or unavailable.');
        $url = self::UPLOAD_API.'/files'.($fileId ? '/'.rawurlencode($fileId) : '');
        $this->uploadPhase = 'UPLOAD_INIT';
        $response = $this->driveRequest($fileId ? 'PATCH' : 'POST', $url, [
            'query' => ['uploadType' => 'resumable', 'fields' => 'id,name,mimeType,size,parents,md5Checksum,appProperties'],
            'json' => $metadata,
            'headers' => ['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $size],
        ]);
        $location = $response->getHeaderLine('Location');
        if (! $this->isGoogleUploadLocation($location)) throw new GoogleDriveArchiveException('Google Drive did not provide a valid upload session.', $response->getStatusCode());

        $stream = fopen($localPath, 'rb');
        if (! is_resource($stream)) throw new GoogleDriveArchiveException('Archive file could not be read.');
        try {
            $this->uploadPhase = 'UPLOAD_BODY';
            $uploaded = $this->driveRequest('PUT', $location, [
                'headers' => ['Content-Type' => $mime, 'Content-Length' => (string) $size],
                'body' => $stream,
            ]);
        } finally {
            // HTTP handlers may close the supplied stream after transfer. An
            // unconditional fclose then throws before the final 200 response
            // can be parsed, even though Drive has already created the file.
            if (is_resource($stream)) fclose($stream);
        }

        $this->uploadPhase = 'RESPONSE_PARSE';
        $data = $this->json($uploaded);
        if (! is_string($data['id'] ?? null)) throw new GoogleDriveArchiveException('Google Drive upload returned no file identity.', $uploaded->getStatusCode());
        return ['file_id' => $data['id'], 'folder_id' => $data['parents'][0] ?? ($metadata['parents'][0] ?? null)];
    }

    private function folderForIdentity(?string $folderId, array $archiveContext, bool $bypassCache = false): string
    {
        $root = $folderId ?: $this->requiredConfig('folder_id');
        if ($folderId && $folderId !== $this->requiredConfig('folder_id')) {
            $this->assertFolder($folderId);
        }
        $segments = $archiveContext['folder_path'] ?? null;
        if (! is_array($segments) || count($segments) !== 3) {
            throw new GoogleDriveArchiveException('Canonical archive folder path is unavailable.');
        }
        $segments = array_map(fn ($segment): string => $this->safeSegment((string) $segment), array_values($segments));

        try {
            return $this->resolveFolderPath($root, $segments, $bypassCache);
        } catch (GoogleDriveArchiveException $exception) {
            if ($bypassCache || $exception->httpStatus !== 404) throw $exception;
            Log::notice('Cached Google Drive archive folder path was stale; resolving it again.', ['segment_count' => count($segments)]);
            return $this->resolveFolderPath($root, $segments, true);
        }
    }

    /** @param list<string> $segments */
    private function resolveFolderPath(string $root, array $segments, bool $bypassCache): string
    {
        $parent = $root;
        foreach ($segments as $segment) $parent = $this->getOrCreateFolder($parent, $segment, $bypassCache);
        return $parent;
    }

    private function assertFolder(string $folderId): void
    {
        $data = $this->json($this->driveRequest('GET', self::API.'/files/'.rawurlencode($folderId), ['query' => ['fields' => 'id,mimeType,trashed']]));
        if (($data['id'] ?? null) !== $folderId || ($data['mimeType'] ?? null) !== self::FOLDER_MIME || ($data['trashed'] ?? false)) {
            throw new GoogleDriveArchiveException('Configured archive parent is not an accessible folder.', 200);
        }
    }

    private function getOrCreateFolder(string $parentId, string $name, bool $bypassCache = false): string
    {
        $cacheKey = $this->folderCacheKey($parentId, $name);
        if (! $bypassCache && is_string($cached = Cache::get($cacheKey)) && $cached !== '') {
            Log::debug('Google Drive archive folder cache hit.', ['cache_hit' => true]);
            return $cached;
        }

        return Cache::lock($cacheKey.'.lock', 30)->block(10, function () use ($parentId, $name, $cacheKey, $bypassCache): string {
            if (! $bypassCache && is_string($cached = Cache::get($cacheKey)) && $cached !== '') {
                Log::debug('Google Drive archive folder cache hit after lock.', ['cache_hit' => true]);
                return $cached;
            }

            $escapedName = str_replace(["\\", "'"], ["\\\\", "\\'"], $name);
            $query = "name = '{$escapedName}' and mimeType = '".self::FOLDER_MIME."' and '{$parentId}' in parents and trashed = false";
            $listed = $this->json($this->driveRequest('GET', self::API.'/files', [
                'query' => ['q' => $query, 'pageSize' => 100, 'fields' => 'files(id,mimeType)'],
            ]));
            foreach (($listed['files'] ?? []) as $folder) {
                if (($folder['mimeType'] ?? null) === self::FOLDER_MIME && is_string($folder['id'] ?? null)) {
                    Cache::put($cacheKey, $folder['id'], now()->addHours(6));
                    return $folder['id'];
                }
            }

            $created = $this->json($this->driveRequest('POST', self::API.'/files', [
                'query' => ['fields' => 'id,mimeType'],
                'json' => ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]],
            ]));
            if (($created['mimeType'] ?? null) !== self::FOLDER_MIME || ! is_string($created['id'] ?? null)) {
                throw new GoogleDriveArchiveException('Google Drive archive folder could not be created.');
            }
            Cache::put($cacheKey, $created['id'], now()->addHours(6));
            return $created['id'];
        });
    }

    private function folderCacheKey(string $parentId, string $name): string
    {
        return 'document-archive.google-drive.folder.'.hash('sha256', $parentId."\0".$name);
    }

    private function fileMetadata(string $filename, ?string $parentId, array $identity, string $sha256, string $mime): array
    {
        $metadata = [
            'name' => $this->safeFilename($filename),
            'mimeType' => $mime,
            'appProperties' => ['cds_identity' => $this->identityHash($identity), 'cds_sha256' => $sha256],
        ];
        if ($parentId !== null) $metadata['parents'] = [$parentId];
        return $metadata;
    }

    private function assertLocalContent(string $path, string $sha256): void
    {
        if (! is_file($path) || ! is_readable($path)) throw new GoogleDriveArchiveException('Archive file is unavailable.');
        $actual = hash_file('sha256', $path);
        if (! is_string($actual) || ! hash_equals($sha256, $actual)) throw new GoogleDriveArchiveException('Archive file checksum does not match.');
    }

    private function identityHash(array $identity): string
    {
        ksort($identity);
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $clientId = $this->requiredConfig('client_id');
        $clientSecret = $this->requiredConfig('client_secret');
        $refreshToken = $this->requiredConfig('refresh_token');
        $cacheKey = 'document-archive.google-drive.token.'.hash('sha256', $clientId);
        if ($forceRefresh) Cache::forget($cacheKey);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;

        $startedAt = hrtime(true);
        try {
            $response = $this->client->request('POST', self::OAUTH, [
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 30,
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);
        } catch (Throwable) {
            Log::debug('Google Drive OAuth refresh failed.', ['duration_ms' => (hrtime(true) - $startedAt) / 1_000_000]);
            throw new GoogleDriveArchiveException('Google Drive OAuth token request failed.');
        }
        Log::debug('Google Drive OAuth refresh completed.', ['duration_ms' => (hrtime(true) - $startedAt) / 1_000_000, 'http_status' => $response->getStatusCode()]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new GoogleDriveArchiveException('Google Drive OAuth token request failed.', $response->getStatusCode());
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new GoogleDriveArchiveException('Google Drive OAuth response was invalid.', $response->getStatusCode());
        }
        if (! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new GoogleDriveArchiveException('Google Drive OAuth response contained no access token.', $response->getStatusCode());
        }
        $ttl = max(60, min(3500, (int) ($data['expires_in'] ?? 3600) - 60));
        Cache::put($cacheKey, $data['access_token'], now()->addSeconds($ttl));
        return $data['access_token'];
    }

    private function driveRequest(string $method, string $url, array $options = [], bool $retryUnauthorized = true): ResponseInterface
    {
        if (! config('services.google_drive_archive.enabled')) {
            throw new GoogleDriveArchiveException('Google Drive archive integration is disabled.');
        }
        if (! $this->isAllowedDriveUrl($url)) {
            throw new GoogleDriveArchiveException('Google Drive request destination was rejected.');
        }
        $headers = $options['headers'] ?? [];
        $headers['Authorization'] = 'Bearer '.$this->accessToken();
        $options['headers'] = $headers;
        $options['http_errors'] = false;
        $options['connect_timeout'] = 10;
        $options['timeout'] = 120;
        $startedAt = hrtime(true);
        $operation = $this->operationName($method, $url, $options);
        try {
            $response = $this->client->request($method, $url, $options);
        } catch (Throwable) {
            Log::debug('Google Drive archive API request failed.', ['operation' => $operation, 'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000]);
            throw new GoogleDriveArchiveException('Google Drive API request failed.');
        }
        Log::debug('Google Drive archive API request completed.', ['operation' => $operation, 'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000, 'http_status' => $response->getStatusCode()]);
        if ($this->uploadPhase !== '' && ($this->isGoogleUploadLocation($url) || str_starts_with($url, self::UPLOAD_API.'/'))) {
            $this->captureUploadResponse($this->uploadPhase, $response);
        }
        if ($response->getStatusCode() === 401 && $retryUnauthorized) {
            $this->accessToken(true);
            if (is_resource($options['body'] ?? null) && stream_get_meta_data($options['body'])['seekable']) rewind($options['body']);
            return $this->driveRequest($method, $url, $options, false);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new GoogleDriveArchiveException('Google Drive API request failed.', $response->getStatusCode());
        }
        return $response;
    }

    /** Store response metadata only; never retain headers, body values, or file bytes. */
    private function captureUploadResponse(string $phase, ResponseInterface $response): void
    {
        $body = $response->getBody();
        $length = $body->getSize();
        $payload = null;
        if ($body->isSeekable()) {
            if ($length === null) {
                $body->seek(0, SEEK_END);
                $length = $body->tell();
            }
            if (is_int($length) && $length <= 65536) {
                $body->seek(0);
                $payload = $body->getContents();
            }
            // Keep the body readable from the beginning for the JSON parser.
            $body->seek(0);
        }
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        $keys = is_array($decoded) ? array_values(array_map('strval', array_keys($decoded))) : [];
        $requestId = null;
        foreach (['X-GUploader-UploadID', 'X-Goog-Request-Id', 'X-Request-Id'] as $header) {
            $value = trim($response->getHeaderLine($header));
            if ($value !== '') {
                $requestId = substr($value, 0, 200);
                break;
            }
        }

        $this->uploadDiagnostics[] = [
            'phase' => $phase,
            'mode' => 'resumable',
            'http_status' => $response->getStatusCode(),
            'content_type' => substr($response->getHeaderLine('Content-Type'), 0, 120) ?: null,
            'response_body_length' => $length,
            'json_top_level_keys' => $keys,
            'id_present' => is_array($decoded) && array_key_exists('id', $decoded),
            'location_present' => trim($response->getHeaderLine('Location')) !== '',
            'request_error_id' => $requestId,
        ];
    }

    private function requiredConfig(string $key): string
    {
        $value = config('services.document_archive.'.$key);
        if (! is_string($value) || trim($value) === '') throw new GoogleDriveArchiveException('Google Drive archive configuration is incomplete.');
        return trim($value);
    }

    private function json(ResponseInterface $response): array
    {
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new GoogleDriveArchiveException('Google Drive returned invalid metadata.', $response->getStatusCode());
        }
        if (! is_array($data)) throw new GoogleDriveArchiveException('Google Drive returned invalid metadata.', $response->getStatusCode());
        return $data;
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '-', trim($value));
        $segment = preg_replace('/\s+/u', ' ', (string) $segment);
        $segment = trim((string) $segment, " .\t\n\r\0\x0B");
        if ($segment === '' || in_array($segment, ['.', '..'], true)) {
            throw new GoogleDriveArchiveException('Canonical archive folder name is unsafe.');
        }

        return $segment;
    }

    private function operationName(string $method, string $url, array $options): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_contains($path, '/upload/drive/v3/')) return $method === 'PUT' ? 'upload.body' : 'upload.session';
        if (str_ends_with($path, '/about')) return 'storage.about';
        if ($path === '/drive/v3/files') return $method === 'GET' ? 'files.search' : 'files.create';
        if (($options['query']['alt'] ?? null) === 'media') return 'file.content.read';
        return match ($method) {
            'GET' => 'file.metadata.read',
            'PATCH' => 'file.metadata.update',
            'DELETE' => 'file.delete',
            default => 'drive.request',
        };
    }

    private function safeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/', '-', $name);
        return trim((string) $name) !== '' ? trim((string) $name) : 'Final Document.pdf';
    }

    private function isGoogleUploadLocation(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($host) && in_array(strtolower($host), ['www.googleapis.com', 'content.googleapis.com'], true)
            && $scheme === 'https' && is_string($path) && str_starts_with($path, '/upload/drive/v3/');
    }

    private function isAllowedDriveUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);
        if ($scheme !== 'https' || ! is_string($host) || ! is_string($path)) return false;

        if (strtolower($host) === 'www.googleapis.com') {
            return str_starts_with($path, '/drive/v3/') || str_starts_with($path, '/upload/drive/v3/');
        }

        return $this->isGoogleUploadLocation($url);
    }
}
