<?php

namespace App\Services\Archive;

/** Explicit non-persistent local fake; never selected as a fallback for Google Drive. */
final class FakeDocumentArchiveGateway implements PreGeneratedIdGoogleDriveArchiveGateway
{
    private array $objects = [];
    private int $nextId = 0;
    private array $archiveContexts = [];
    private int $uploadAttempts = 0;
    private int $idGenerationAttempts = 0;
    private int $availabilityChecks = 0;
    private int $preGeneratedVerificationChecks = 0;
    private int $retrievals = 0;
    private bool $rejectNextIdGeneration = false;
    private ?string $rejectNextIdGenerationCategory = null;
    private bool $rejectNextUpload = false;
    private ?\Throwable $rejectNextUploadWith = null;
    private ?int $rejectUploadAtAttempt = null;
    private bool $throwAfterNextUpload = false;
    private ?\Closure $afterNextUpload = null;
    private ?\Closure $beforeNextIdGeneration = null;
    private array $mimeTypes = [];
    private array $reservedIds = [];

    public function rejectNextUpload(?\Throwable $exception = null): void
    {
        $this->rejectNextUpload = true;
        $this->rejectNextUploadWith = $exception;
    }

    public function rejectNextIdGeneration(?string $oauthErrorCategory = null): void
    {
        $this->rejectNextIdGeneration = true;
        $this->rejectNextIdGenerationCategory = $oauthErrorCategory;
    }

    /** Run a one-shot assertion hook immediately before the provider ID reservation call. */
    public function beforeNextIdGeneration(callable $callback): void
    {
        $this->beforeNextIdGeneration = \Closure::fromCallable($callback);
    }

    public function rejectUploadAtAttempt(int $attempt): void
    {
        $this->rejectUploadAtAttempt = max(1, $attempt);
    }

    /** Simulate a provider that persisted the object but lost its response. */
    public function throwAfterNextUpload(): void
    {
        $this->throwAfterNextUpload = true;
    }

    /** Run a one-shot controlled interleaving after object persistence but before the response returns. */
    public function afterNextUpload(callable $callback): void
    {
        $this->afterNextUpload = \Closure::fromCallable($callback);
    }

    public function uploadAttempts(): int
    {
        return $this->uploadAttempts;
    }

    public function idGenerationAttempts(): int
    {
        return $this->idGenerationAttempts;
    }

    public function availabilityChecks(): int
    {
        return $this->availabilityChecks;
    }

    public function preGeneratedVerificationChecks(): int
    {
        return $this->preGeneratedVerificationChecks;
    }

    public function retrievals(): int
    {
        return $this->retrievals;
    }

    public function objectCount(): int
    {
        return count($this->objects);
    }

    /** @return list<string> */
    public function objectIds(): array
    {
        return array_values(array_keys($this->objects));
    }

    public function findByIdentityAndHash(array $identity, string $sha256): ?array
    {
        foreach ($this->objects as $id => $object) {
            if ($object['identity'] === $identity && hash_equals($object['sha256'], $sha256)) {
                return ['file_id' => $id, 'folder_id' => $object['folder_id']];
            }
        }
        return null;
    }

    public function generatePreGeneratedFileId(): string
    {
        $this->idGenerationAttempts++;
        if ($this->beforeNextIdGeneration) {
            $callback = $this->beforeNextIdGeneration;
            $this->beforeNextIdGeneration = null;
            $callback();
        }
        if ($this->rejectNextIdGeneration) {
            $this->rejectNextIdGeneration = false;
            $category = $this->rejectNextIdGenerationCategory;
            $this->rejectNextIdGenerationCategory = null;
            throw new GoogleDriveArchiveException('Injected fake Google Drive ID reservation failure.', 400, $category);
        }
        $id = 'fake-reserved-'.(++$this->nextId);
        $this->reservedIds[$id] = true;
        return $id;
    }

    public function verifyPreGeneratedObject(string $fileId, array $identity, string $sha256, int $size, ?string $folderId, array $archiveContext): array
    {
        $this->preGeneratedVerificationChecks++;
        $object = $this->objects[$fileId] ?? null;
        if ($object === null) return ['status' => 'unavailable', 'file_id' => null, 'folder_id' => $folderId];
        if ($object['identity'] !== $identity || $object['folder_id'] !== $folderId
            || strlen($object['bytes']) !== $size || ! hash_equals($object['sha256'], $sha256)
            || ! hash_equals($sha256, hash('sha256', $object['bytes']))) {
            return ['status' => 'content_mismatch', 'file_id' => null, 'folder_id' => $object['folder_id']];
        }
        return ['status' => 'verified', 'file_id' => $fileId, 'folder_id' => $object['folder_id']];
    }

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        return $this->uploadWithMime($localPath, $filename, $identity, $sha256, $folderId, $archiveContext, 'application/pdf');
    }

    public function uploadWithMime(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        return $this->storeUpload('fake-archive-'.(++$this->nextId), $localPath, $filename, $identity, $sha256, $folderId, $archiveContext, $mimeType);
    }

    public function uploadUsingPreGeneratedId(string $preGeneratedFileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        if (! isset($this->reservedIds[$preGeneratedFileId])) {
            throw new GoogleDriveArchiveException('The fake archive ID was not pre-generated.', 400);
        }
        if (isset($this->objects[$preGeneratedFileId])) {
            throw new GoogleDriveArchiveException('The fake archive ID already exists.', 409);
        }
        return $this->storeUpload($preGeneratedFileId, $localPath, $filename, $identity, $sha256, $folderId, $archiveContext, $mimeType);
    }

    private function storeUpload(string $id, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        $this->uploadAttempts++;
        if ($this->rejectNextUpload || $this->rejectUploadAtAttempt === $this->uploadAttempts) {
            $this->rejectNextUpload = false;
            $this->rejectUploadAtAttempt = null;
            if ($this->rejectNextUploadWith) {
                $exception = $this->rejectNextUploadWith;
                $this->rejectNextUploadWith = null;
                throw $exception;
            }
            throw new \RuntimeException('Injected fake archive rejection.');
        }
        $this->objects[$id] = ['identity' => $identity, 'sha256' => $sha256, 'bytes' => file_get_contents($localPath), 'folder_id' => $folderId];
        $this->archiveContexts[] = ['filename' => $filename, 'identity' => $identity, 'context' => $archiveContext];
        $this->mimeTypes[$id] = $mimeType;
        if ($this->afterNextUpload !== null) {
            $callback = $this->afterNextUpload;
            $this->afterNextUpload = null;
            $callback($id);
        }
        if ($this->throwAfterNextUpload) {
            $this->throwAfterNextUpload = false;
            throw new \RuntimeException('Injected lost provider response after object creation.');
        }
        return ['file_id' => $id, 'folder_id' => $folderId];
    }

    public function verify(string $fileId, string $sha256, int $size): bool
    {
        return $this->verifyAvailability($fileId, $sha256, $size) === 'verified';
    }

    public function verifyAvailability(string $fileId, string $sha256, int $size): string
    {
        $this->availabilityChecks++;
        $object = $this->objects[$fileId] ?? null;
        if ($object === null) return 'unavailable';
        return strlen($object['bytes']) === $size && hash_equals($object['sha256'], $sha256)
            && hash_equals($sha256, hash('sha256', $object['bytes'])) ? 'verified' : 'content_mismatch';
    }

    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        return $this->replaceWithMime($fileId, $localPath, $filename, $identity, $sha256, $folderId, $archiveContext, 'application/pdf');
    }

    public function replaceWithMime(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        $this->objects[$fileId] = ['identity' => $identity, 'sha256' => $sha256, 'bytes' => file_get_contents($localPath), 'folder_id' => $folderId];
        $this->archiveContexts[] = ['filename' => $filename, 'identity' => $identity, 'context' => $archiveContext];
        $this->mimeTypes[$fileId] = $mimeType;
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    /** @return list<array{filename:string,identity:array,context:array}> */
    public function archiveContexts(): array
    {
        return $this->archiveContexts;
    }

    public function mimeTypes(): array
    {
        return $this->mimeTypes;
    }

    public function retrieve(string $fileId)
    {
        $this->retrievals++;
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) ($this->objects[$fileId]['bytes'] ?? ''));
        rewind($stream);
        return $stream;
    }
}
