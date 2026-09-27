<?php

namespace App\Services\Archive;

use LogicException;

/** Fails closed when the archive driver is absent or invalid. */
final class UnconfiguredGoogleDriveArchiveGateway implements GoogleDriveArchiveGateway
{
    public function findByIdentityAndHash(array $identity, string $sha256): ?array { throw new LogicException('Google Drive archive integration is not configured.'); }
    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array { throw new LogicException('Google Drive archive integration is not configured.'); }
    public function verify(string $fileId, string $sha256, int $size): bool { throw new LogicException('Google Drive archive integration is not configured.'); }
    public function verifyAvailability(string $fileId, string $sha256, int $size): string { throw new LogicException('Google Drive archive integration is not configured.'); }
    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array { throw new LogicException('Google Drive archive integration is not configured.'); }
    public function retrieve(string $fileId) { throw new LogicException('Google Drive archive integration is not configured.'); }
}
