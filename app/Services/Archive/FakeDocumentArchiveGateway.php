<?php

namespace App\Services\Archive;

/** Explicit non-persistent local fake; never selected as a fallback for Google Drive. */
final class FakeDocumentArchiveGateway implements MimeAwareGoogleDriveArchiveGateway
{
    private array $objects = [];
    private int $nextId = 0;
    private array $archiveContexts = [];
    private int $uploadAttempts = 0;
    private bool $rejectNextUpload = false;
    private ?int $rejectUploadAtAttempt = null;
    private bool $throwAfterNextUpload = false;
    private array $mimeTypes = [];

    public function rejectNextUpload(): void
    {
        $this->rejectNextUpload = true;
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

    public function uploadAttempts(): int
    {
        return $this->uploadAttempts;
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

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        return $this->uploadWithMime($localPath, $filename, $identity, $sha256, $folderId, $archiveContext, 'application/pdf');
    }

    public function uploadWithMime(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        $this->uploadAttempts++;
        if ($this->rejectNextUpload || $this->rejectUploadAtAttempt === $this->uploadAttempts) {
            $this->rejectNextUpload = false;
            $this->rejectUploadAtAttempt = null;
            throw new \RuntimeException('Injected fake archive rejection.');
        }
        $id = 'fake-archive-'.(++$this->nextId);
        $this->objects[$id] = ['identity' => $identity, 'sha256' => $sha256, 'bytes' => file_get_contents($localPath), 'folder_id' => $folderId];
        $this->archiveContexts[] = ['filename' => $filename, 'identity' => $identity, 'context' => $archiveContext];
        $this->mimeTypes[$id] = $mimeType;
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
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) ($this->objects[$fileId]['bytes'] ?? ''));
        rewind($stream);
        return $stream;
    }
}
