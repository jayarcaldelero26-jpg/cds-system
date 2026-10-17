<?php

namespace App\Services\Archive;

/** Optional Drive capability for create-once binary uploads with a durable ID. */
interface PreGeneratedIdGoogleDriveArchiveGateway extends MimeAwareGoogleDriveArchiveGateway
{
    public function generatePreGeneratedFileId(): string;

    /** @param array<string,string|int> $identity @return array{file_id:string,folder_id:?string} */
    public function uploadUsingPreGeneratedId(
        string $preGeneratedFileId,
        string $localPath,
        string $filename,
        array $identity,
        string $sha256,
        ?string $folderId,
        array $archiveContext,
        string $mimeType,
    ): array;

    /** @param array<string,string|int> $identity @return array{status:'verified'|'unavailable'|'content_mismatch'|'unknown',file_id:?string,folder_id:?string} */
    public function verifyPreGeneratedObject(
        string $fileId,
        array $identity,
        string $sha256,
        int $size,
        ?string $folderId,
        array $archiveContext,
    ): array;
}
