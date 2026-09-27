<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentArchive;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Presents read-only storage diagnostics from CDS-SMART-owned metadata. */
final class SubmissionStorageStatusPresenter
{
    public function __construct(private readonly ReportDocumentAdapterResolver $documents) {}

    /** @return array{hostinger:array{state:string,label:string},google_drive:array{state:string,label:string}} */
    public function present(string $source, Model $record): array
    {
        $resolved = $this->documents->resolveOfficialDocumentSlot($source, $record);
        $reference = null;

        if ($resolved['status'] === ReportDocumentAdapterResolver::SUPPORTED && $resolved['adapter'] !== null) {
            $reference = $resolved['adapter']->read($record, (string) $resolved['slot']);
        }

        $path = $reference['path'] ?? null;
        $hostingerStored = is_string($path) && $path !== '' && Storage::disk('local')->exists($path);
        $hostinger = ! $path
            ? ['state' => 'none', 'label' => 'No Document']
            : ($hostingerStored
                ? ['state' => 'stored', 'label' => 'Stored']
                : ['state' => 'missing', 'label' => 'Missing']);

        $archive = $resolved['status'] === ReportDocumentAdapterResolver::SUPPORTED
            ? DocumentArchive::query()
                ->where('source_type', $source)
                ->where('source_id', $record->getKey())
                ->where('logical_slot', $resolved['slot'])
                ->latest('id')
                ->first()
            : null;

        $googleDrive = match ($archive?->archive_status) {
            'ARCHIVED' => ! filled($archive->google_drive_file_id)
                ? ['state' => 'failed', 'label' => 'Archive Failed']
                : match ($archive->remote_availability) {
                    'unavailable' => ['state' => 'unavailable', 'label' => 'Archive Unavailable'],
                    default => ['state' => 'archived', 'label' => 'Archived'],
                },
            'PENDING' => ['state' => 'pending', 'label' => 'Pending'],
            'FAILED' => ['state' => 'failed', 'label' => 'Archive Failed'],
            default => ['state' => 'not_archived', 'label' => 'Not Archived Yet'],
        };

        return ['hostinger' => $hostinger, 'google_drive' => $googleDrive];
    }
}
