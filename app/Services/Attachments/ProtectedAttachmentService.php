<?php

namespace App\Services\Attachments;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsRecord;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaAssessment;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ManagementPlanProfile;
use App\Models\TechnicalReport;
use App\Models\DocumentArchive;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Explicit source registry for protected active-module attachments.
 *
 * The browser supplies only a registered source, record id, and registered
 * attachment key/index. Filesystem paths are resolved from the source record.
 */
final class ProtectedAttachmentService
{
    private const PRIVATE_DISK = 'local';
    private const HISTORICAL_DISK = 'public';

    public function __construct(private readonly GoogleDriveArchiveGateway $archiveGateway) {}

    /** @return array<string, array<string, mixed>> */
    public function registry(): array
    {
        return [
            'conservation-report' => ['model' => ConservationReportSubmission::class, 'ability' => 'technical-reports.view', 'folder' => 'conservation-report-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'routing_source' => 'conservation', 'official_key' => 'mov'],
            'bms-data' => ['model' => BmsRecord::class, 'ability' => 'bms.view', 'folder' => 'bms-attachments', 'kind' => 'scalar', 'key' => 'attachment', 'path' => 'attachment'],
            'bms-report' => ['model' => BmsReportSubmission::class, 'ability' => 'bms.view', 'folder' => 'bms-report-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'routing_source' => 'bms', 'official_key' => 'mov'],
            'bams-report' => ['model' => BamsReportSubmission::class, 'ability' => 'bams.view', 'folder' => 'bams-report-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'routing_source' => 'bams', 'official_key' => 'mov'],
            'imea-data' => ['model' => ImeaAssessment::class, 'ability' => 'imea.view', 'folder' => 'imea-attachments', 'kind' => 'json', 'key' => 'attachments', 'field' => 'attachments'],
            'imea-report' => ['model' => ImeaReportSubmission::class, 'ability' => 'imea.view', 'folder' => 'imea-report-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'routing_source' => 'imea', 'official_key' => 'mov'],
            'imea-maintenance' => ['model' => ImeaFacilityMaintenanceReport::class, 'ability' => 'imea.view', 'folder' => 'imea-maintenance-report-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'routing_source' => 'imea-maintenance', 'official_key' => 'mov'],
            'aws' => ['model' => Aws::class, 'ability' => 'aws.view', 'folder' => 'aws_reports', 'kind' => 'scalar', 'key' => 'report_file', 'path' => 'report_file_path', 'name' => 'report_file_name', 'routing_source' => 'aws', 'official_key' => 'report_file'],
            'management-plan' => ['model' => ManagementPlan::class, 'ability' => 'management-plans.view', 'folder' => 'management-plans', 'kind' => 'json', 'key' => 'attachments', 'field' => 'attachments', 'routing_source' => 'management-plans', 'official_key' => '0'],
            'management-plan-profile' => ['model' => ManagementPlanProfile::class, 'ability' => 'management-plans.view', 'folder' => 'management-plan-profiles', 'kind' => 'json', 'key' => 'documents', 'field' => 'documents'],
            'technical-report' => ['model' => TechnicalReport::class, 'ability' => 'technical-reports.view', 'folder' => 'technical-reports', 'kind' => 'scalar', 'key' => 'attachment', 'path' => 'attachment', 'name' => 'attachment_original_name', 'mime' => 'attachment_mime_type', 'size' => 'attachment_size'],
            'engp-report' => ['model' => EngpReportSubmission::class, 'ability' => 'technical-reports.view', 'folder' => 'engp-report-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'external' => 'mov_external_url', 'routing_source' => 'engp', 'official_key' => 'mov'],
            'ipaf-management' => ['model' => IpafManagementReport::class, 'ability' => 'technical-reports.view', 'folder' => 'ipaf-management-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'mime' => 'mov_mime_type', 'size' => 'mov_size', 'routing_source' => 'ipaf-management', 'official_key' => 'mov'],
            'ipaf-revenue' => ['model' => IpafRevenueCollection::class, 'ability' => 'technical-reports.view', 'folder' => 'ipaf-revenue-movs', 'kind' => 'scalar', 'key' => 'mov', 'path' => 'mov_file_path', 'name' => 'mov_file_name', 'mime' => 'mov_mime_type', 'size' => 'mov_size', 'routing_source' => 'revenue', 'official_key' => 'mov'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function definition(string $source): ?array
    {
        return $this->registry()[$source] ?? null;
    }

    /** @return array{source:string,definition:array<string,mixed>}|null */
    public function officialDefinitionForRoutingSource(string $routingSource): ?array
    {
        foreach ($this->registry() as $source => $definition) {
            if (($definition['routing_source'] ?? null) === $routingSource
                && is_string($definition['official_key'] ?? null)) {
                return ['source' => $source, 'definition' => $definition];
            }
        }

        return null;
    }

    /** @return list<array{key:string,path:mixed,external:?string}> */
    public function migrationReferences(string $source, Model $record): array
    {
        $definition = $this->definition($source);
        if ($definition === null || ! $record instanceof $definition['model']) {
            return [];
        }

        $references = [];
        $externalField = $definition['external'] ?? null;
        if (is_string($externalField)) {
            $external = $record->getAttribute($externalField);
            if (is_string($external) && trim($external) !== '') {
                $references[] = ['key' => 'external', 'path' => null, 'external' => $external];
            }
        }

        if ($definition['kind'] === 'scalar') {
            $path = $record->getAttribute($definition['path']);
            if ($path !== null && $path !== '') {
                $references[] = ['key' => $definition['key'], 'path' => $path, 'external' => null];
            }

            return $references;
        }

        $attachments = $record->getAttribute($definition['field']);
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true);
        }
        if (! is_array($attachments)) {
            return $references;
        }

        foreach ($attachments as $index => $attachment) {
            $path = is_array($attachment)
                ? ($attachment['path'] ?? $attachment['file_path'] ?? null)
                : $attachment;
            if ($path !== null && $path !== '') {
                $references[] = ['key' => (string) $index, 'path' => $path, 'external' => null];
            }
        }

        return $references;
    }

    /** @return array{status:string,path:string,private?:array<string,mixed>,public?:array<string,mixed>} */
    public function migrationPathInfo(mixed $path): array
    {
        if (! $this->safePath($path)) {
            return ['status' => 'invalid', 'path' => is_string($path) ? $path : ''];
        }

        $normalized = $this->normalizePath($path);
        $private = $this->findDiskPath(self::PRIVATE_DISK, $normalized);
        $public = $this->findDiskPath(self::HISTORICAL_DISK, $normalized);

        if ($private === null && $public === null) {
            return ['status' => 'missing', 'path' => $normalized];
        }

        if ($private !== null && $public !== null) {
            return [
                'status' => $this->sameFiles($private, $public) ? 'identical' : 'collision',
                'path' => $normalized,
                'private' => $private,
                'public' => $public,
            ];
        }

        return [
            'status' => $private !== null ? 'private' : 'public',
            'path' => $normalized,
            ...($private !== null ? ['private' => $private] : ['public' => $public]),
        ];
    }

    /** @return array{status:string,path:string,message?:string} */
    public function copyHistoricalToPrivate(string $path): array
    {
        $info = $this->migrationPathInfo($path);
        if ($info['status'] !== 'public' || ! isset($info['public']['absolute'])) {
            return ['status' => $info['status'], 'path' => $info['path']];
        }

        $stream = fopen($info['public']['absolute'], 'rb');
        if ($stream === false) {
            return ['status' => 'failed', 'path' => $info['path'], 'message' => 'The historical file could not be opened.'];
        }

        try {
            $stored = Storage::disk(self::PRIVATE_DISK)->put($info['path'], $stream);
        } finally {
            fclose($stream);
        }

        $copied = $this->findDiskPath(self::PRIVATE_DISK, $info['path']);
        if (! $stored || $copied === null || ! $this->sameFiles($copied, $info['public'])) {
            Storage::disk(self::PRIVATE_DISK)->delete($info['path']);

            return ['status' => 'failed', 'path' => $info['path'], 'message' => 'The private copy failed verification.'];
        }

        return ['status' => 'copied', 'path' => $info['path']];
    }

    public function store(UploadedFile $file, string $source): string
    {
        $definition = $this->definition($source);
        abort_unless($definition, 404);

        $path = $file->store($definition['folder'], self::PRIVATE_DISK);
        abort_unless(is_string($path) && $path !== '', 500);

        return $path;
    }

    public function delete(?string $path): void
    {
        if (! $this->safePath($path)) {
            return;
        }

        foreach ($this->pathVariants($path) as $candidate) {
            Storage::disk(self::PRIVATE_DISK)->delete($candidate);
            Storage::disk(self::HISTORICAL_DISK)->delete($candidate);
        }
    }

    public function url(string $source, Model $record, string $key): string
    {
        abort_unless($this->definition($source), 404);

        return route('attachments.show', [
            'source' => $source,
            'record' => $record->getKey(),
            'attachment' => $key,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function descriptor(string $source, Model $record, string $key): ?array
    {
        $resolved = $this->resolveRecordAttachment($source, $record, $key);
        if ($resolved === null || $resolved['path'] === null) {
            return null;
        }

        $diskPath = $this->resolveDiskPath($resolved['path']);
        if ($diskPath === null) {
            return null;
        }

        $info = $this->fileInfo($resolved['path'], $resolved['mime'], $resolved['size'], $diskPath);

        return [
            'key' => $key,
            'name' => $resolved['name'] ?: basename($resolved['path']),
            'mime_type' => $info['mime_type'],
            'type' => $info['mime_type'],
            'size' => $info['size'],
            'url' => $this->url($source, $record, $key),
            'external' => false,
        ];
    }

    /**
     * Descriptor for an inline-preview decision. Unlike the general download
     * descriptor, this never infers MIME from a filename/path extension: use
     * stored MIME metadata or server-side content detection, otherwise leave
     * the type unknown so the client can offer download without embedding it.
     *
     * @return array<string, mixed>|null
     */
    public function previewDescriptor(string $source, Model $record, string $key): ?array
    {
        $resolved = $this->resolveRecordAttachment($source, $record, $key);
        if ($resolved === null || $resolved['path'] === null) {
            return null;
        }

        $diskPath = $this->resolveDiskPath($resolved['path']);
        if ($diskPath === null) {
            return null;
        }

        $mimeType = is_string($resolved['mime'] ?? null) && trim($resolved['mime']) !== ''
            ? trim($resolved['mime'])
            : null;
        if ($mimeType === null && function_exists('mime_content_type')) {
            $detected = mime_content_type($diskPath['absolute']);
            $mimeType = is_string($detected) && trim($detected) !== '' ? trim($detected) : null;
        }

        $name = $resolved['name'] ?: basename($resolved['path']);
        $url = $this->url($source, $record, $key);
        $previewUrl = $url.'?preview=1';

        return [
            'key' => $key,
            'name' => $name,
            'mime_type' => $mimeType,
            'type' => $mimeType,
            'size' => is_numeric($resolved['size'] ?? null) ? (int) $resolved['size'] : (int) filesize($diskPath['absolute']),
            'url' => $url,
            'preview_url' => $previewUrl,
            'download_url' => $url.'?download=1',
            'external' => false,
        ];
    }

    public function response(string $source, Model $record, string $key): Response
    {
        abort_unless($this->definition($source), 404);
        $resolved = $this->resolveRecordAttachment($source, $record, $key);
        abort_unless($resolved !== null && $resolved['path'] !== null, 404);
        $currentDocumentPreview = request()->boolean('preview');

        $diskPath = $this->resolveDiskPath($resolved['path']);
        if ($diskPath === null) {
            // Full Details preview is strictly for the selected source record's
            // current local document. Never fall through to an archived copy.
            abort_if($currentDocumentPreview, 404);
            return $this->archivedResponse($source, $record, $key, $resolved);
        }

        $info = $this->fileInfo($resolved['path'], $resolved['mime'], $resolved['size'], $diskPath, ! $currentDocumentPreview);
        $filename = $this->safeFilename($resolved['name'] ?: basename($resolved['path']));
        $disposition = request()->boolean('download') || ! $this->isInlineMime($info['mime_type'], $filename, ! $currentDocumentPreview) ? 'attachment' : 'inline';

        return response()->file($diskPath['absolute'], [
            'Content-Type' => $info['mime_type'],
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Caller must authorize the report before invoking this protected fallback. */
    private function archivedResponse(string $source, Model $record, string $key, array $resolved): Response
    {
        $definition = $this->definition($source);
        abort_unless($definition !== null
            && ($definition['official_key'] ?? null) === $key
            && is_string($definition['routing_source'] ?? null), 404);

        $archive = DocumentArchive::query()
            ->where('source_type', $definition['routing_source'])
            ->where('source_id', $record->getKey())
            ->where('logical_slot', $key)
            ->where('archive_status', 'ARCHIVED')
            ->first();
        abort_unless($archive && filled($archive->google_drive_file_id)
            && filled($archive->archived_sha256), 404);
        abort_if($archive->remote_availability === 'unavailable', 404);

        try {
            $sourceStream = $this->archiveGateway->retrieve($archive->google_drive_file_id);
        } catch (\Throwable $exception) {
            $archive->forceFill([
                'remote_availability' => $exception instanceof GoogleDriveArchiveException && $exception->httpStatus === 404 ? 'unavailable' : 'unknown',
                'last_verified_at' => now(),
                'last_verification_error_class' => $exception instanceof GoogleDriveArchiveException && $exception->httpStatus === 404 ? 'NOT_FOUND' : 'PROVIDER_ERROR',
            ])->save();
            abort(404);
        }
        abort_unless(is_resource($sourceStream), 404);
        $stream = fopen('php://temp', 'w+b');
        abort_unless(is_resource($stream), 404);
        $hash = hash_init('sha256');
        $bytes = 0;
        while (! feof($sourceStream)) {
            $chunk = fread($sourceStream, 8192);
            if ($chunk === false) break;
            if ($chunk === '') break;
            $bytes += strlen($chunk);
            hash_update($hash, $chunk);
            fwrite($stream, $chunk);
        }
        fclose($sourceStream);
        $sha256 = hash_final($hash);
        if ($bytes !== (int) $archive->archived_size || ! hash_equals($archive->archived_sha256, $sha256)) {
            fclose($stream);
            $archive->forceFill(['remote_availability' => 'unknown', 'last_verified_at' => now(), 'last_verification_error_class' => 'CONTENT_MISMATCH'])->save();
            abort(404);
        }
        $archive->forceFill(['remote_availability' => 'verified', 'last_verified_at' => now(), 'last_verification_error_class' => null])->save();
        rewind($stream);

        $filename = $this->safeFilename($archive->original_filename ?: $resolved['name'] ?: basename((string) $resolved['path']));
        $mime = $resolved['mime'] ?: (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : 'application/octet-stream');
        $disposition = request()->boolean('download') || ! $this->isInlineMime($mime, $filename) ? 'attachment' : 'inline';

        return response()->stream(function () use ($stream): void {
            $output = fopen('php://output', 'wb');
            if ($output !== false) stream_copy_to_stream($stream, $output);
            if (is_resource($output)) fclose($output);
            if (is_resource($stream)) fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Content-Length' => (string) $archive->archived_size,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function resolveRecordAttachment(string $source, Model $record, string $key): ?array
    {
        $definition = $this->definition($source);
        if ($definition === null || $key === '') {
            return null;
        }

        $modelClass = $definition['model'];
        if (! $record instanceof $modelClass) {
            return null;
        }

        if ($definition['kind'] === 'scalar') {
            if ($key !== $definition['key']) {
                return null;
            }

            $path = $record->getAttribute($definition['path']);
            return [
                'path' => $this->safePath($path) ? $path : null,
                'name' => ($definition['name'] ?? null) ? $record->getAttribute($definition['name']) : null,
                'mime' => ($definition['mime'] ?? null) ? $record->getAttribute($definition['mime']) : null,
                'size' => ($definition['size'] ?? null) ? $record->getAttribute($definition['size']) : null,
            ];
        }

        if (! ctype_digit($key)) {
            return null;
        }

        $attachments = $record->getAttribute($definition['field']);
        if (! is_array($attachments)) {
            return null;
        }

        $item = $attachments[(int) $key] ?? null;
        $metadata = is_array($item) ? $item : ['path' => $item];
        $path = $metadata['path'] ?? null;

        return [
            'path' => $this->safePath($path) ? $path : null,
            'name' => $metadata['original_name'] ?? $metadata['name'] ?? $metadata['stored_name'] ?? null,
            'mime' => $metadata['mime_type'] ?? $metadata['type'] ?? null,
            'size' => $metadata['size'] ?? null,
        ];
    }

    /** @return array{absolute:string,disk:string}|null */
    private function resolveDiskPath(string $path): ?array
    {
        foreach ([self::PRIVATE_DISK, self::HISTORICAL_DISK] as $disk) {
            $found = $this->findDiskPath($disk, $path);
            if ($found !== null) {
                return ['absolute' => $found['absolute'], 'disk' => $disk];
            }
        }

        return null;
    }

    /** @return array{candidate:string,absolute:string,size:int}|null */
    private function findDiskPath(string $disk, string $path): ?array
    {
        foreach ($this->pathVariants($path) as $candidate) {
            if (! Storage::disk($disk)->exists($candidate)) {
                continue;
            }

            $absolute = realpath(Storage::disk($disk)->path($candidate));
            $base = realpath(Storage::disk($disk)->path(''));
            if ($absolute === false || $base === false || ! str_starts_with($absolute, rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || ! is_file($absolute)) {
                continue;
            }

            return ['candidate' => $candidate, 'absolute' => $absolute, 'size' => (int) filesize($absolute)];
        }

        return null;
    }

    private function sameFiles(array $left, array $right): bool
    {
        if (($left['size'] ?? null) !== ($right['size'] ?? null)) {
            return false;
        }

        $leftHash = hash_file('sha256', $left['absolute']);
        $rightHash = hash_file('sha256', $right['absolute']);

        return is_string($leftHash) && is_string($rightHash) && hash_equals($leftHash, $rightHash);
    }

    /** @return list<string> */
    private function pathVariants(string $path): array
    {
        $normalized = str_replace('\\', '/', $path);
        $variants = [$path];
        if (str_starts_with($normalized, 'public/')) {
            $variants[] = substr($normalized, strlen('public/'));
        }

        return array_values(array_unique($variants));
    }

    private function normalizePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /** @return array{mime_type:string,size:int|null} @param array{absolute:string,disk:string}|null $diskPath */
    private function fileInfo(string $path, mixed $mime, mixed $size, ?array $diskPath = null, bool $allowExtensionMime = true): array
    {
        $diskPath ??= $this->resolveDiskPath($path);
        $absolute = $diskPath['absolute'] ?? null;
        $detected = $absolute && function_exists('mime_content_type') ? mime_content_type($absolute) : false;
        $mimeType = is_string($mime) && $mime !== '' ? $mime : (is_string($detected) && $detected !== '' ? $detected : ($allowExtensionMime ? $this->mimeFromExtension($path) : null));
        $fileSize = is_numeric($size) ? (int) $size : ($absolute && is_file($absolute) ? filesize($absolute) : null);

        return ['mime_type' => $mimeType ?: 'application/octet-stream', 'size' => $fileSize ?: null];
    }

    private function isInlineMime(string $mime, string $filename, bool $allowExtensionFallback = true): bool
    {
        if (in_array(strtolower($mime), ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            return true;
        }

        return $allowExtensionFallback
            && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true);
    }

    private function mimeFromExtension(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    private function safePath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized)) {
            return false;
        }

        return ! str_starts_with($normalized, '/')
            && ! preg_match('/^[A-Za-z]:\\//', $normalized)
            && ! in_array('..', explode('/', $normalized), true);
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = str_replace('\\', '_', $filename);
        $filename = preg_replace('/[\x00-\x1F\x7F"]+/', '_', $filename) ?: 'attachment';
        return trim($filename, '. ') ?: 'attachment';
    }
}
