<?php

namespace App\Services\Archive;

/** Optional provider capability for source contracts that permit non-PDF files. */
interface MimeAwareGoogleDriveArchiveGateway extends GoogleDriveArchiveGateway
{
    /** @param array<string,string|int> $identity @return array{file_id:string,folder_id:?string} */
    public function uploadWithMime(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array;

    /** @param array<string,string|int> $identity @return array{file_id:string,folder_id:?string} */
    public function replaceWithMime(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext, string $mimeType): array;
}
