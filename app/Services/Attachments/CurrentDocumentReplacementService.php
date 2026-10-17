<?php

namespace App\Services\Attachments;

use App\Models\DocumentAttachmentHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Shared safe document lifecycle primitive. The caller must authorize first. */
final class CurrentDocumentReplacementService
{
    public const DISK = 'local';
    public const MAX_KB = 102400;
    private const MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    /** @param callable(Model):void $authorize */
    public function replace(
        Model $record,
        string $sourceType,
        string $slot,
        UploadedFile $file,
        string $pathColumn,
        string $filenameColumn,
        int $actorId,
        callable $authorize,
        ?string $remarks = null,
        array $attributes = [],
    ): array {
        return $this->replaceUsingAdapter(
            $record,
            $sourceType,
            $slot,
            $file,
            new ScalarDocumentReferenceAdapter($pathColumn, $filenameColumn),
            $slot,
            $actorId,
            $authorize,
            $remarks,
            $attributes,
        );
    }

    /** @param callable(Model):void $authorize */
    public function replaceUsingAdapter(
        Model $record,
        string $sourceType,
        string $logicalSlot,
        UploadedFile $file,
        DocumentReferenceAdapter $adapter,
        string $historySlot,
        int $actorId,
        callable $authorize,
        ?string $remarks = null,
        array $attributes = [],
        string $action = 'REPLACEMENT',
    ): array {
        $startedAt = hrtime(true);
        $authorize($record);
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/i', $sourceType)
            || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/i', $logicalSlot)
            || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/i', $historySlot)) {
            throw ValidationException::withMessages(['attachment' => 'The document slot is not supported.']);
        }
        if (! $file->isValid() || $file->getSize() > self::MAX_KB * 1024
            || ! in_array((string) $file->getMimeType(), self::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['attachment' => 'The uploaded document is invalid or unsupported.']);
        }
        $incomingHash = hash_file('sha256', $file->getRealPath());
        if (! is_string($incomingHash)) {
            throw ValidationException::withMessages(['attachment' => 'The document could not be verified.']);
        }

        $expected = $adapter->read($record, $logicalSlot);
        $expectedPath = $expected['path'];
        $old = $this->existingFile(is_string($expectedPath) ? $expectedPath : null);
        if ($old && hash_equals($old['sha256'], $incomingHash)) {
            DB::transaction(function () use ($record, $adapter, $logicalSlot, $expected, $attributes): void {
                $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                if ($adapter->read($locked, $logicalSlot)['token'] !== $expected['token']) {
                    throw ValidationException::withMessages(['attachment' => 'This document was changed by another user. Reload and try again.']);
                }
                $locked->fill($attributes)->saveOrFail();
            });
            $this->retryPendingSupersededCleanup($record, $sourceType, $historySlot);
            Log::debug('Optional routing document replacement resolved as an identical-file no-op.', ['source_type' => $sourceType, 'logical_slot' => $historySlot, 'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000]);
            return ['status' => 'duplicate', 'path' => $expectedPath, 'sha256' => $incomingHash];
        }

        $newPath = $file->store($this->folder($sourceType, $record, $logicalSlot), self::DISK);
        if (! is_string($newPath) || $newPath === '' || ! Storage::disk(self::DISK)->exists($newPath)) {
            throw ValidationException::withMessages(['attachment' => 'The document could not be stored.']);
        }
        $storedHash = hash_file('sha256', Storage::disk(self::DISK)->path($newPath));
        if (! is_string($storedHash) || ! hash_equals($incomingHash, $storedHash)) {
            Storage::disk(self::DISK)->delete($newPath);
            throw ValidationException::withMessages(['attachment' => 'The stored document failed verification.']);
        }

        $newFilename = mb_strcut(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 250);
        try {
            DB::transaction(function () use ($record, $sourceType, $historySlot, $logicalSlot, $adapter, $expected, $expectedPath, $newPath, $newFilename, $incomingHash, $actorId, $remarks, $attributes, $action): void {
                $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
                $current = $adapter->read($locked, $logicalSlot);
                $currentPath = $current['path'];
                if ($current['token'] !== $expected['token']) {
                    throw ValidationException::withMessages(['attachment' => 'This document was changed by another user. Reload and try again.']);
                }
                $previous = $this->existingFile(is_string($currentPath) ? $currentPath : null);
                $adapter->write($locked, $logicalSlot, $newPath, $newFilename);
                $locked->fill($attributes);
                $locked->saveOrFail();
                DocumentAttachmentHistory::query()->create([
                    'source_type' => $sourceType, 'source_id' => $locked->getKey(), 'logical_slot' => $historySlot,
                    'actor_id' => $actorId, 'action' => $action === 'REPLACEMENT' ? ($previous ? 'Replaced' : 'Uploaded') : $action, 'remarks' => $remarks,
                    'old_path' => $currentPath, 'old_filename' => $previous ? ($current['filename'] ?: basename($currentPath)) : null,
                    'old_sha256' => $previous['sha256'] ?? null, 'old_size' => $previous['size'] ?? null,
                    'new_path' => $newPath, 'new_filename' => $newFilename, 'new_sha256' => $incomingHash,
                    'new_size' => Storage::disk(self::DISK)->size($newPath),
                ]);
            });
        } catch (Throwable $exception) {
            if (! Storage::disk(self::DISK)->delete($newPath)) {
                Log::warning('Uncommitted replacement file could not be removed.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $historySlot]);
            }
            throw $exception;
        }

        $freshPath = $adapter->read($record->newQuery()->findOrFail($record->getKey()), $logicalSlot)['path'];
        if ($freshPath !== $newPath || ! Storage::disk(self::DISK)->exists($newPath)) {
            Log::critical('Committed attachment replacement failed reference verification.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $historySlot]);
            throw new \RuntimeException('The document replacement requires administrator review.');
        }
        if (is_string($expectedPath) && $expectedPath !== '' && $expectedPath !== $newPath && $old) {
            $this->scheduleSupersededFileCleanup($expectedPath, $old['disk'], $sourceType, $record, $historySlot);
        }
        $this->retryPendingSupersededCleanup($record, $sourceType, $historySlot);

        Log::debug('Routing document replacement completed.', ['source_type' => $sourceType, 'logical_slot' => $historySlot, 'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000]);
        return ['status' => $expectedPath === null ? 'uploaded' : 'replaced', 'path' => $newPath, 'sha256' => $incomingHash];
    }

    /** Promote an already staged, verified final file without making a second permanent copy. */
    public function adoptStoredUsingAdapter(
        Model $record,
        string $sourceType,
        string $logicalSlot,
        string $path,
        string $filename,
        string $expectedSha256,
        int $expectedSize,
        DocumentReferenceAdapter $adapter,
        int $actorId,
        callable $authorize,
    ): array {
        $authorize($record);
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/i', $sourceType)
            || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/i', $logicalSlot)
            || ! $this->safeRelativePath($path) || ! Storage::disk(self::DISK)->exists($path)) {
            throw ValidationException::withMessages(['attachment' => 'The verified final document is not available for promotion.']);
        }

        $absolute = Storage::disk(self::DISK)->path($path);
        $actualHash = hash_file('sha256', $absolute);
        $actualSize = (int) Storage::disk(self::DISK)->size($path);
        if (! is_string($actualHash) || ! hash_equals($expectedSha256, $actualHash) || $actualSize !== $expectedSize) {
            throw ValidationException::withMessages(['attachment' => 'The final document changed before its current-file reference could be updated.']);
        }

        $expected = $adapter->read($record, $logicalSlot);
        $oldPath = $expected['path'];
        if ($oldPath === $path && $expected['filename'] === $filename) {
            return ['status' => 'unchanged', 'path' => $path, 'sha256' => $actualHash];
        }
        $old = $this->existingFile(is_string($oldPath) ? $oldPath : null);

        DB::transaction(function () use ($record, $sourceType, $logicalSlot, $path, $filename, $actualHash, $actualSize, $adapter, $actorId, $authorize, $expected, $oldPath): void {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $authorize($locked);
            $current = $adapter->read($locked, $logicalSlot);
            if ($current['token'] !== $expected['token']) {
                throw ValidationException::withMessages(['attachment' => 'The current document changed during archive verification. Reload and review the report.']);
            }
            $previous = $this->existingFile(is_string($oldPath) ? $oldPath : null);
            $adapter->write($locked, $logicalSlot, $path, $filename);
            $locked->saveOrFail();
            DocumentAttachmentHistory::query()->create([
                'source_type' => $sourceType, 'source_id' => $locked->getKey(), 'logical_slot' => $logicalSlot,
                'actor_id' => $actorId, 'action' => 'Finalized',
                'remarks' => 'The verified Regional-return final copy became the current official document.',
                'old_path' => $oldPath, 'old_filename' => $previous ? ($current['filename'] ?: basename((string) $oldPath)) : null,
                'old_sha256' => $previous['sha256'] ?? null, 'old_size' => $previous['size'] ?? null,
                'new_path' => $path, 'new_filename' => $filename, 'new_sha256' => $actualHash, 'new_size' => $actualSize,
            ]);
        });

        $fresh = $adapter->read($record->newQuery()->findOrFail($record->getKey()), $logicalSlot);
        if ($fresh['path'] !== $path || ! Storage::disk(self::DISK)->exists($path)
            || ! hash_equals($expectedSha256, (string) hash_file('sha256', Storage::disk(self::DISK)->path($path)))) {
            throw new \RuntimeException('The verified final document reference could not be confirmed.');
        }
        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $path && $old) {
            $this->scheduleSupersededFileCleanup($oldPath, $old['disk'], $sourceType, $record, $logicalSlot);
        }
        $this->retryPendingSupersededCleanup($record, $sourceType, $logicalSlot);

        return ['status' => 'finalized', 'path' => $path, 'sha256' => $actualHash];
    }

    /** @param callable(Model):void $authorize */
    public function clearUsingAdapter(Model $record, string $sourceType, string $logicalSlot, DocumentReferenceAdapter $adapter, int $actorId, callable $authorize, ?string $remarks = null): void
    {
        $authorize($record);
        $expected = $adapter->read($record, $logicalSlot);
        $oldPath = $expected['path'];
        $oldFile = $this->existingFile($oldPath);
        DB::transaction(function () use ($record, $sourceType, $logicalSlot, $adapter, $actorId, $authorize, $remarks, $expected, $oldPath): void {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $authorize($locked);
            $current = $adapter->read($locked, $logicalSlot);
            if ($current['token'] !== $expected['token']) {
                throw ValidationException::withMessages(['attachment' => 'This document was changed by another user. Reload and try again.']);
            }
            if ($current['path'] === null) return;
            $old = $this->existingFile($current['path']);
            $adapter->clear($locked, $logicalSlot);
            $locked->saveOrFail();
            DocumentAttachmentHistory::query()->create([
                'source_type' => $sourceType, 'source_id' => $locked->getKey(), 'logical_slot' => $logicalSlot,
                'actor_id' => $actorId, 'action' => 'Removed', 'remarks' => $remarks,
                'old_path' => $oldPath, 'old_filename' => $current['filename'] ?: basename((string) $oldPath),
                'old_sha256' => $old['sha256'] ?? null, 'old_size' => $old['size'] ?? null,
            ]);
        });

        $fresh = $adapter->read($record->newQuery()->findOrFail($record->getKey()), $logicalSlot);
        if ($fresh['path'] !== null) throw new \RuntimeException('The document slot could not be cleared.');
        if (is_string($oldPath) && $oldPath !== '' && $oldFile) {
            $this->scheduleSupersededFileCleanup($oldPath, $oldFile['disk'], $sourceType, $record, $logicalSlot);
        }
        $this->retryPendingSupersededCleanup($record, $sourceType, $logicalSlot);
    }

    /**
     * Retry a bounded set of superseded bytes recorded in the existing
     * attachment audit history. This runs only after the caller's transaction
     * commits and never deletes a path whose current-reference token is live.
     */
    public function retryPendingSupersededCleanup(Model $record, string $sourceType, string $slot, int $limit = 25): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->deletePendingSupersededCleanup($record, $sourceType, $slot, $limit));
            return;
        }

        $this->deletePendingSupersededCleanup($record, $sourceType, $slot, $limit);
    }

    private function deletePendingSupersededCleanup(Model $record, string $sourceType, string $slot, int $limit): void
    {
        $history = DocumentAttachmentHistory::query()
            ->where('source_type', $sourceType)->where('source_id', $record->getKey())->where('logical_slot', $slot)
            ->whereIn('action', ['Replaced', 'Removed', 'Finalized'])
            ->whereNotNull('old_path')->whereNotNull('old_sha256')
            ->latest('id')->limit(max(1, min($limit, 100)))
            ->get(['old_path', 'old_sha256', 'old_size']);

        foreach ($history as $entry) {
            $path = (string) $entry->old_path;
            if (! $this->safeRelativePath($path)
                || app(ProtectedAttachmentService::class)->hasLivePathReference($path)) continue;
            $file = $this->existingFile($path);
            if (! $file || ! hash_equals((string) $entry->old_sha256, $file['sha256'])
                || ($entry->old_size !== null && (int) $entry->old_size !== $file['size'])) continue;
            if (! Storage::disk($file['disk'])->delete($path)) {
                Log::warning('Superseded attachment cleanup remains retryable from bounded audit history.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $slot]);
            }
        }
    }

    /** @return array{sha256:string,size:int,disk:string}|null */
    private function existingFile(?string $path): ?array
    {
        if (! $path || ! $this->safeRelativePath($path)) return null;
        foreach ([self::DISK, 'public'] as $disk) {
            if (! Storage::disk($disk)->exists($path)) continue;
            $absolute = Storage::disk($disk)->path($path);
            $hash = hash_file('sha256', $absolute);
            if (is_string($hash)) return ['sha256' => $hash, 'size' => Storage::disk($disk)->size($path), 'disk' => $disk];
        }
        return null;
    }

    private function safeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        return $normalized !== '' && ! str_starts_with($normalized, '/')
            && ! preg_match('/^[A-Za-z]:\//', $normalized)
            && ! in_array('..', explode('/', $normalized), true)
            && ! str_contains($normalized, "\0");
    }

    private function scheduleSupersededFileCleanup(string $path, string $disk, string $sourceType, Model $record, string $slot): void
    {
        if (! $this->safeRelativePath($path)) return;
        $cleanup = static function () use ($path, $disk, $sourceType, $record, $slot): void {
            if (app(ProtectedAttachmentService::class)->hasLivePathReference($path)) {
                Log::debug('Superseded attachment bytes were retained because another live slot references them.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $slot]);
                return;
            }
            if (! Storage::disk($disk)->delete($path)) {
                Log::warning('Superseded attachment cleanup did not complete; its bounded history reference remains available for retry.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $slot]);
            }
        };
        if (DB::transactionLevel() > 0) DB::afterCommit($cleanup);
        else $cleanup();
    }

    private function folder(string $sourceType, Model $record, string $slot): string
    {
        return 'current-documents/'.strtolower($sourceType).'/'.$record->getKey().'/'.strtolower($slot);
    }
}
