<?php

namespace App\Services\Archive;

use Illuminate\Support\Facades\Schema;

/** Shared activation boundary for the manual Regional-return archive lifecycle. */
final class FinalReturnArchiveAvailability
{
    public function enabled(): bool
    {
        return (bool) config('services.document_archive.final_return_archive_enabled', false)
            && $this->schemaReady();
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('final_document_receipts')
            && Schema::hasTable('final_document_archive_manifests')
            && Schema::hasTable('final_document_archive_files')
            && Schema::hasColumn('final_document_archive_manifests', 'signature_set_sha256')
            && Schema::hasColumn('document_archives', 'archive_phase');
    }
}
