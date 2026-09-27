<?php

namespace App\Services\Archive;

use Illuminate\Validation\ValidationException;

/** Shared gate for the mandatory PENRO Records → Office of the PENRO checkpoint. */
final class ArchiveCheckpointPolicy
{
    public function isCheckpoint(string $action, string $from, string $to): bool
    {
        return $action === 'forward_to_office_penro'
            && $from === 'penro_records'
            && $to === 'transit_to_office_of_penro';
    }

    public function assertTransitionAllowed(string $action, string $from, string $to): void
    {
        if ($this->isCheckpoint($action, $from, $to)) $this->assertEnabledAndConfigured();
    }

    public function assertEnabledAndConfigured(): void
    {
        // Do not inspect credentials or resolve provider operations when disabled.
        if (! config('services.google_drive_archive.enabled')) {
            throw ValidationException::withMessages(['archive' => 'The required PENRO Records archive checkpoint is disabled. Contact an administrator before forwarding.']);
        }

        $driver = config('services.document_archive.driver');
        if ($driver === 'fake' && ! app()->environment('production')) return;
        if ($driver !== 'google-drive') {
            throw ValidationException::withMessages(['archive' => 'The required PENRO Records archive checkpoint is not configured. Routing was not advanced.']);
        }
        foreach (['client_id', 'client_secret', 'refresh_token', 'folder_id'] as $key) {
            if (! filled(config('services.document_archive.'.$key))) {
                throw ValidationException::withMessages(['archive' => 'The required PENRO Records archive checkpoint is not fully configured. Routing was not advanced.']);
            }
        }
    }
}
