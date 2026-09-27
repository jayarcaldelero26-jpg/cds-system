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
        if (is_string($expectedPath) && $expectedPath !== '' && $this->safeRelativePath($expectedPath) && $expectedPath !== $newPath && $old) {
            $cleanup = static function () use ($old, $expectedPath, $sourceType, $record, $historySlot): void {
                if (! Storage::disk($old['disk'])->delete($expectedPath)) {
                    Log::warning('Previous attachment could not be removed after a successful replacement.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $historySlot]);
                }
            };
            if (DB::transactionLevel() > 0) DB::afterCommit($cleanup);
            else $cleanup();
        }

        Log::debug('Routing document replacement completed.', ['source_type' => $sourceType, 'logical_slot' => $historySlot, 'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000]);
        return ['status' => $expectedPath === null ? 'uploaded' : 'replaced', 'path' => $newPath, 'sha256' => $incomingHash];
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
        if (is_string($oldPath) && $oldPath !== '' && $this->safeRelativePath($oldPath) && $oldFile
            && ! Storage::disk($oldFile['disk'])->delete($oldPath)) {
            Log::warning('Removed attachment file could not be deleted after its reference was cleared.', ['source_type' => $sourceType, 'source_id' => $record->getKey(), 'logical_slot' => $logicalSlot]);
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

    private function folder(string $sourceType, Model $record, string $slot): string
    {
        return 'current-documents/'.strtolower($sourceType).'/'.$record->getKey().'/'.strtolower($slot);
    }
}
