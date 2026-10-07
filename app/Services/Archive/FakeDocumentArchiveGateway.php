<?php

namespace App\Services\Archive;

/** Explicit non-persistent local fake; never selected as a fallback for Google Drive. */
final class FakeDocumentArchiveGateway implements GoogleDriveArchiveGateway
{
    private array $objects = [];
    private int $nextId = 0;
    private array $archiveContexts = [];
    private int $uploadAttempts = 0;
    private bool $rejectNextUpload = false;

    public function rejectNextUpload(): void
    {
        $this->rejectNextUpload = true;
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
        $this->uploadAttempts++;
        if ($this->rejectNextUpload) {
            $this->rejectNextUpload = false;
            throw new \RuntimeException('Injected fake archive rejection.');
        }
        $id = 'fake-archive-'.(++$this->nextId);
        $this->objects[$id] = ['identity' => $identity, 'sha256' => $sha256, 'bytes' => file_get_contents($localPath), 'folder_id' => $folderId];
        $this->archiveContexts[] = ['filename' => $filename, 'identity' => $identity, 'context' => $archiveContext];
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
        $this->objects[$fileId] = ['identity' => $identity, 'sha256' => $sha256, 'bytes' => file_get_contents($localPath), 'folder_id' => $folderId];
        $this->archiveContexts[] = ['filename' => $filename, 'identity' => $identity, 'context' => $archiveContext];
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    /** @return list<array{filename:string,identity:array,context:array}> */
    public function archiveContexts(): array
    {
        return $this->archiveContexts;
    }

    public function retrieve(string $fileId)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) ($this->objects[$fileId]['bytes'] ?? ''));
        rewind($stream);
        return $stream;
    }
}
