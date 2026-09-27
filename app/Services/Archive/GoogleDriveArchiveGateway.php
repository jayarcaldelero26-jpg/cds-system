<?php

namespace App\Services\Archive;

/** Provider boundary; implementations must use Drive IDs, never public links. */
interface GoogleDriveArchiveGateway
{
    /** @param array<string,string|int> $identity @return array{file_id:string,folder_id:?string}|null */
    public function findByIdentityAndHash(array $identity, string $sha256): ?array;

    /** @param array<string,string|int> $identity @return array{file_id:string,folder_id:?string} */
    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array;

    public function verify(string $fileId, string $sha256, int $size): bool;

    /** @return 'verified'|'unavailable'|'content_mismatch'|'unknown' */
    public function verifyAvailability(string $fileId, string $sha256, int $size): string;

    /** @return array{file_id:string,folder_id:?string} */
    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array;

    /** Returns a readable binary stream after caller authorization. @return resource */
    public function retrieve(string $fileId);
}
