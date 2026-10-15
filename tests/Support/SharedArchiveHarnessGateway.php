<?php

namespace Tests\Support;

use App\Services\Archive\MimeAwareGoogleDriveArchiveGateway;
use RuntimeException;

/** File-backed test provider used only by the marker-owned MySQL archive harness. */
final class SharedArchiveHarnessGateway implements MimeAwareGoogleDriveArchiveGateway
{
    public function findByIdentityAndHash(array $identity, string $sha256): ?array
    {
        if (getenv('CDS_ARCHIVE_FAKE_FAULT') === 'find_uncertain') {
            $this->observe(['operation' => 'find', 'outcome' => 'unknown']);
            throw new RuntimeException('Injected inconclusive provider identity search.');
        }
        $identityHash = $this->identityHash($identity);
        $state = $this->readState();
        foreach ($state['objects'] as $object) {
            if (($object['identity_hash'] ?? null) === $identityHash && hash_equals((string) ($object['sha256'] ?? ''), $sha256)) {
                $this->observe(['operation' => 'find', 'outcome' => 'found', 'file_id' => $object['file_id']]);
                return ['file_id' => $object['file_id'], 'folder_id' => $object['folder_id']];
            }
        }
        $this->observe(['operation' => 'find', 'outcome' => 'missing']);
        return null;
    }

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        return $this->uploadWithMime($localPath, $filename, $identity, $sha256, $folderId, $archiveContext, 'application/pdf');
    }

    public function uploadWithMime(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        $bytes = file_get_contents($localPath);
        if (! is_string($bytes) || ! hash_equals($sha256, hash('sha256', $bytes))) throw new RuntimeException('Harness provider received invalid local bytes.');
        $state = $this->readState();
        $attempt = (int) ($state['upload_attempts'] ?? 0) + 1;
        $state['upload_attempts'] = $attempt;
        $this->writeState($state);
        $rejectAt = (int) (getenv('CDS_ARCHIVE_FAKE_REJECT_UPLOAD_AT') ?: 0);
        if ($rejectAt > 0 && $attempt === $rejectAt) {
            $this->observe(['operation' => 'upload', 'outcome' => 'rejected', 'attempt' => $attempt]);
            throw new RuntimeException('Injected provider upload rejection.');
        }

        $fileId = 'harness-'.substr(hash('sha256', $this->identityHash($identity).'|'.$sha256), 0, 36);
        $objectPath = $this->taskRoot().DIRECTORY_SEPARATOR.'provider-objects'.DIRECTORY_SEPARATOR.$fileId.'.bin';
        if (! is_dir(dirname($objectPath))) mkdir(dirname($objectPath), 0700, true);
        file_put_contents($objectPath, $bytes, LOCK_EX);
        $state = $this->readState();
        $state['objects'][$fileId] = [
            'file_id' => $fileId, 'identity_hash' => $this->identityHash($identity), 'identity' => $identity,
            'sha256' => $sha256, 'size' => strlen($bytes), 'folder_id' => $folderId,
            'filename' => $filename, 'mime_type' => $mimeType, 'object_path' => $objectPath,
        ];
        $this->writeState($state);
        $this->observe(['operation' => 'upload', 'outcome' => 'persisted', 'attempt' => $attempt, 'file_id' => $fileId, 'identity' => $identity, 'sha256' => $sha256]);

        $signal = getenv('CDS_ARCHIVE_FAKE_PAUSE_SIGNAL');
        $release = getenv('CDS_ARCHIVE_FAKE_PAUSE_RELEASE');
        if (is_string($signal) && $signal !== '' && is_string($release) && $release !== '') {
            file_put_contents($signal, 'persisted');
            $deadline = microtime(true) + 20;
            while (! is_file($release)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Provider overlap barrier timed out.');
                usleep(10000);
            }
        }
        if (getenv('CDS_ARCHIVE_FAKE_FAULT') === 'crash_after_upload') exit(86);
        if (getenv('CDS_ARCHIVE_FAKE_FAULT') === 'lost_response') throw new RuntimeException('Injected persisted object with lost response.');

        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    public function verify(string $fileId, string $sha256, int $size): bool
    {
        return $this->verifyAvailability($fileId, $sha256, $size) === 'verified';
    }

    public function verifyAvailability(string $fileId, string $sha256, int $size): string
    {
        if (getenv('CDS_ARCHIVE_FAKE_FAULT') === 'verification_mismatch') {
            $this->observe(['operation' => 'verify', 'outcome' => 'mismatch', 'file_id' => $fileId]);
            return 'content_mismatch';
        }
        $object = $this->readState()['objects'][$fileId] ?? null;
        if (! is_array($object) || ! is_file((string) ($object['object_path'] ?? ''))) return 'unavailable';
        $actual = hash_file('sha256', $object['object_path']);
        $result = (int) $object['size'] === $size && (int) filesize($object['object_path']) === $size
            && hash_equals((string) $object['sha256'], $sha256) && is_string($actual) && hash_equals($sha256, $actual)
                ? 'verified' : 'content_mismatch';
        $this->observe(['operation' => 'verify', 'outcome' => $result, 'file_id' => $fileId]);
        return $result;
    }

    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        return $this->replaceWithMime($fileId, $localPath, $filename, $identity, $sha256, $folderId, $archiveContext, 'application/pdf');
    }

    public function replaceWithMime(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array
    {
        $existing = $this->readState()['objects'][$fileId] ?? null;
        if (! is_array($existing)) throw new RuntimeException('Harness replacement requested for an unknown provider object.');
        $bytes = file_get_contents($localPath);
        if (! is_string($bytes) || ! hash_equals($sha256, hash('sha256', $bytes))) throw new RuntimeException('Harness provider replacement content mismatch.');
        file_put_contents((string) $existing['object_path'], $bytes, LOCK_EX);
        $state = $this->readState();
        $state['objects'][$fileId] = [...$existing, 'identity_hash' => $this->identityHash($identity), 'identity' => $identity, 'sha256' => $sha256, 'size' => strlen($bytes), 'folder_id' => $folderId, 'filename' => $filename, 'mime_type' => $mimeType];
        $this->writeState($state);
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    public function retrieve(string $fileId)
    {
        $object = $this->readState()['objects'][$fileId] ?? null;
        if (! is_array($object) || ! is_file((string) ($object['object_path'] ?? ''))) throw new RuntimeException('Harness provider object is unavailable.');
        $stream = fopen((string) $object['object_path'], 'rb');
        if (! is_resource($stream)) throw new RuntimeException('Harness provider object could not be opened.');
        return $stream;
    }

    public function uploadAttempts(): int
    {
        return (int) ($this->readState()['upload_attempts'] ?? 0);
    }

    public function objects(): array
    {
        return $this->readState()['objects'] ?? [];
    }

    private function taskRoot(): string
    {
        $root = realpath((string) getenv('CDS_ARCHIVE_TASK_ROOT'));
        if (! $root || ! str_starts_with($root, realpath(base_path('.tmp')).DIRECTORY_SEPARATOR.'cds-final-archive-mysql-')) {
            throw new RuntimeException('Shared archive fake is outside its owned task root.');
        }
        return $root;
    }

    private function identityHash(array $identity): string
    {
        ksort($identity);
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function statePath(): string
    {
        return $this->taskRoot().DIRECTORY_SEPARATOR.'provider-state.json';
    }

    private function readState(): array
    {
        $path = $this->statePath();
        $handle = fopen($path, 'c+b');
        if (! is_resource($handle)) throw new RuntimeException('Shared archive fake state could not be read.');
        try {
            if (! flock($handle, LOCK_SH)) throw new RuntimeException('Shared archive fake read lock failed.');
            rewind($handle);
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        if (! is_string($contents) || trim($contents) === '') return ['upload_attempts' => 0, 'objects' => []];
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) throw new RuntimeException('Shared archive fake state is corrupt.');
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $handle = fopen($path, 'c+b');
        if (! is_resource($handle)) throw new RuntimeException('Shared archive fake state could not be written.');
        try {
            if (! flock($handle, LOCK_EX)) throw new RuntimeException('Shared archive fake write lock failed.');
            ftruncate($handle, 0);
            rewind($handle);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($handle, $encoded) !== strlen($encoded)) throw new RuntimeException('Shared archive fake state write was incomplete.');
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function observe(array $observation): void
    {
        $path = $this->taskRoot().DIRECTORY_SEPARATOR.'provider-observations.jsonl';
        file_put_contents($path, json_encode(['at' => gmdate('c'), ...$observation], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
