<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentAttachmentHistory;
use App\Models\DocumentArchive;
use App\Models\ManagementPlan;
use App\Models\ModuleDefinition;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Archive\FinalDocumentArchiver;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\StructuredDocumentReferenceAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

function archiveTestModule(): ModuleDefinition
{
    return ModuleDefinition::query()->firstOrCreate(['code' => 'bms'], [
        'name' => 'BMS', 'program_area' => 'protected_area_management_and_development',
        'implementation_type' => 'specialized', 'module_type' => 'regular_target',
        'deadline_mode' => 'standard_working_days', 'is_active' => true,
    ]);
}

function replacementTestRecord(): array
{
    $user = User::factory()->create();
    $record = BmsReportSubmission::query()->create([
        'semester' => '1st Semester',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return [$user, $record];
}

final class FakeGoogleDriveArchiveGateway implements GoogleDriveArchiveGateway
{
    public array $objects = [];
    public int $uploadCount = 0;
    public bool $failVerification = false;
    public ?string $forcedAvailability = null;
    public int $verificationCount = 0;
    public ?string $archiveStatusAtUpload = null;

    public function findByIdentityAndHash(array $identity, string $sha256): ?array
    {
        foreach ($this->objects as $key => $object) if ($object['identity'] === $identity && $object['sha256'] === $sha256) return ['file_id' => $key, 'folder_id' => $object['folder_id']];
        return null;
    }

    public function upload(string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $id = 'fake-drive-'.(++$this->uploadCount);
        $this->archiveStatusAtUpload = DocumentArchive::query()->where($identity)->value('archive_status');
        $this->objects[$id] = ['identity' => $identity, 'sha256' => $sha256, 'size' => filesize($localPath), 'folder_id' => $folderId, 'bytes' => file_get_contents($localPath)];
        return ['file_id' => $id, 'folder_id' => $folderId];
    }

    public function verify(string $fileId, string $sha256, int $size): bool
    {
        return $this->verifyAvailability($fileId, $sha256, $size) === 'verified';
    }

    public function verifyAvailability(string $fileId, string $sha256, int $size): string
    {
        $this->verificationCount++;
        if ($this->forcedAvailability !== null) return $this->forcedAvailability;
        if ($this->failVerification) return 'unknown';
        return ($this->objects[$fileId]['sha256'] ?? null) === $sha256 && ($this->objects[$fileId]['size'] ?? null) === $size ? 'verified' : 'content_mismatch';
    }

    public function replace(string $fileId, string $localPath, string $filename, array $identity, string $sha256, ?string $folderId, array $archiveContext = []): array
    {
        $this->objects[$fileId] = ['identity' => $identity, 'sha256' => $sha256, 'size' => filesize($localPath), 'folder_id' => $folderId, 'bytes' => file_get_contents($localPath)];
        return ['file_id' => $fileId, 'folder_id' => $folderId];
    }

    public function retrieve(string $fileId)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) ($this->objects[$fileId]['bytes'] ?? ''));
        rewind($stream);
        return $stream;
    }
}

test('current document replacement verifies content, deduplicates identical uploads, and removes only the previous file', function (): void {
    Storage::fake('local');
    [$user, $record] = replacementTestRecord();
    $service = app(CurrentDocumentReplacementService::class);
    $authorize = static fn (): null => null;

    $first = $service->replace($record, 'bms', 'mov', UploadedFile::fake()->createWithContent('one.pdf', '%PDF-1.4 first'), 'mov_file_path', 'mov_file_name', $user->id, $authorize);
    expect($first['status'])->toBe('uploaded');
    $record->refresh();
    $oldPath = $record->mov_file_path;
    Storage::disk('local')->assertExists($oldPath);

    $duplicate = $service->replace($record, 'bms', 'mov', UploadedFile::fake()->createWithContent('one.pdf', '%PDF-1.4 first'), 'mov_file_path', 'mov_file_name', $user->id, $authorize);
    expect($duplicate['status'])->toBe('duplicate')
        ->and($duplicate['path'])->toBe($oldPath);
    expect(Storage::disk('local')->allFiles('current-documents/bms/'.$record->id.'/mov'))->toHaveCount(1);

    $second = $service->replace($record, 'bms', 'mov', UploadedFile::fake()->createWithContent('two.pdf', '%PDF-1.4 second'), 'mov_file_path', 'mov_file_name', $user->id, $authorize);
    $record->refresh();
    expect($second['status'])->toBe('replaced')
        ->and($record->mov_file_path)->toBe($second['path'])
        ->and($record->mov_file_path)->not->toBe($oldPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($record->mov_file_path);
    expect(Storage::disk('local')->allFiles('current-documents/bms/'.$record->id.'/mov'))->toHaveCount(1)
        ->and(DocumentAttachmentHistory::query()->where('source_id', $record->id)->count())->toBe(2);
});

function structuredReplacementTestRecord(): array
{
    $user = User::factory()->create();
    $area = ProtectedArea::create([
        'name' => 'Lifecycle JSON Area', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $record = ManagementPlan::query()->create([
        'protected_area_id' => $area->id, 'plan_type' => 'PAMP', 'title' => 'Lifecycle contract plan',
        'version' => '1', 'prepared_year' => 2026, 'status' => 'Draft', 'created_by' => $user->id,
        'updated_by' => $user->id, 'attachments' => [],
    ]);

    return [$user, $record];
}

test('structured reference adapter uploads and replaces one JSON slot while preserving siblings and their files', function (): void {
    Storage::fake('local');
    [$user, $record] = structuredReplacementTestRecord();
    $siblings = [
        'resolution' => ['path' => 'legacy/resolution.pdf', 'original_name' => 'Resolution.pdf'],
        'attendance' => 'legacy/attendance.pdf',
    ];
    Storage::disk('local')->put('legacy/resolution.pdf', '%PDF-1.4 resolution');
    Storage::disk('local')->put('legacy/attendance.pdf', '%PDF-1.4 attendance');
    $record->update(['attachments' => $siblings]);
    $adapter = new StructuredDocumentReferenceAdapter('attachments');
    $service = app(CurrentDocumentReplacementService::class);
    $allow = static fn (): null => null;

    $first = $service->replaceUsingAdapter(
        $record, 'management-plan', 'mov', UploadedFile::fake()->createWithContent('mov.pdf', '%PDF-1.4 first'),
        $adapter, 'mov', $user->id, $allow,
    );
    $record->refresh();
    expect($first['status'])->toBe('uploaded')
        ->and(array_keys($record->attachments))->toBe(['resolution', 'attendance', 'mov']);
    Storage::disk('local')->assertExists('legacy/resolution.pdf');
    Storage::disk('local')->assertExists('legacy/attendance.pdf');
    $oldMov = $record->attachments['mov']['path'];

    $duplicate = $service->replaceUsingAdapter(
        $record, 'management-plan', 'mov', UploadedFile::fake()->createWithContent('mov.pdf', '%PDF-1.4 first'),
        $adapter, 'mov', $user->id, $allow,
    );
    expect($duplicate['status'])->toBe('duplicate')
        ->and($record->fresh()->attachments['mov']['path'])->toBe($oldMov);

    $replacement = $service->replaceUsingAdapter(
        $record, 'management-plan', 'mov', UploadedFile::fake()->createWithContent('mov-v2.pdf', '%PDF-1.4 second'),
        $adapter, 'mov', $user->id, $allow, null, [], 'RELEASE',
    );
    $record->refresh();
    expect($replacement['status'])->toBe('replaced')
        ->and($record->attachments['resolution'])->toBe($siblings['resolution'])
        ->and($record->attachments['attendance'])->toBe($siblings['attendance'])
        ->and($record->attachments['mov']['path'])->toBe($replacement['path'])
        ->and($record->attachments['mov']['original_name'])->toBe('mov-v2.pdf');
    Storage::disk('local')->assertMissing($oldMov);
    Storage::disk('local')->assertExists('legacy/resolution.pdf');
    Storage::disk('local')->assertExists('legacy/attendance.pdf');
    expect(Storage::disk('local')->allFiles('current-documents/management-plan/'.$record->id.'/mov'))->toHaveCount(1)
        ->and(DocumentAttachmentHistory::query()->where('source_type', 'management-plan')->where('source_id', $record->id)->where('logical_slot', 'mov')->pluck('action')->all())->toBe(['Uploaded', 'RELEASE']);
});

test('structured-slot database failure preserves all siblings and removes only the new orphan', function (): void {
    Storage::fake('local');
    [$user, $record] = structuredReplacementTestRecord();
    $original = [
        'mov' => ['path' => 'legacy/mov.pdf', 'original_name' => 'MOV.pdf'],
        'resolution' => ['path' => 'legacy/resolution.pdf', 'original_name' => 'Resolution.pdf'],
    ];
    Storage::disk('local')->put('legacy/mov.pdf', '%PDF-1.4 old mov');
    Storage::disk('local')->put('legacy/resolution.pdf', '%PDF-1.4 resolution');
    $record->update(['attachments' => $original]);
    $structuredAdapter = new StructuredDocumentReferenceAdapter('attachments');
    $adapter = new class($structuredAdapter) implements \App\Services\Attachments\DocumentReferenceAdapter {
        public function __construct(private readonly StructuredDocumentReferenceAdapter $inner) {}
        public function read(\Illuminate\Database\Eloquent\Model $record, string $logicalSlot): array { return $this->inner->read($record, $logicalSlot); }
        public function write(\Illuminate\Database\Eloquent\Model $record, string $logicalSlot, string $path, string $filename): void
        {
            $this->inner->write($record, $logicalSlot, $path, $filename);
            $record->setAttribute('lifecycle_forced_invalid_column', 'force database update failure');
        }
        public function clear(\Illuminate\Database\Eloquent\Model $record, string $logicalSlot): void { $this->inner->clear($record, $logicalSlot); }
    };
    $service = app(CurrentDocumentReplacementService::class);
    $allow = static fn (): null => null;

    // Force the DB write to fail after the replacement binary has been stored.
    expect(fn () =>
        $service->replaceUsingAdapter(
            $record, 'management-plan', 'mov', UploadedFile::fake()->createWithContent('new.pdf', '%PDF-1.4 new mov'),
            $adapter, 'mov', $user->id, $allow,
        )
    )->toThrow(QueryException::class);

    expect(ManagementPlan::query()->findOrFail($record->getKey())->attachments)->toBe($original)
        ->and(DocumentAttachmentHistory::query()->where('source_type', 'management-plan')->where('source_id', $record->getKey())->count())->toBe(0);
    Storage::disk('local')->assertExists('legacy/mov.pdf');
    Storage::disk('local')->assertExists('legacy/resolution.pdf');
    expect(Storage::disk('local')->allFiles('current-documents/management-plan/'.$record->id.'/mov'))->toBe([]);
});

test('structured reference adapter clears only the selected logical slot', function (): void {
    Storage::fake('local');
    [$user, $record] = structuredReplacementTestRecord();
    Storage::disk('local')->put('legacy/mov.pdf', '%PDF-1.4 mov');
    Storage::disk('local')->put('legacy/resolution.pdf', '%PDF-1.4 resolution');
    $record->update(['attachments' => [
        'mov' => ['path' => 'legacy/mov.pdf', 'original_name' => 'MOV.pdf'],
        'resolution' => ['path' => 'legacy/resolution.pdf', 'original_name' => 'Resolution.pdf'],
    ]]);

    app(CurrentDocumentReplacementService::class)->clearUsingAdapter(
        $record, 'management-plan', 'mov', new StructuredDocumentReferenceAdapter('attachments'), $user->id,
        static fn (): null => null,
    );

    expect($record->fresh()->attachments)->toBe([
        'resolution' => ['path' => 'legacy/resolution.pdf', 'original_name' => 'Resolution.pdf'],
    ]);
    Storage::disk('local')->assertMissing('legacy/mov.pdf');
    Storage::disk('local')->assertExists('legacy/resolution.pdf');
    $this->assertDatabaseHas('document_attachment_histories', [
        'source_type' => 'management-plan', 'source_id' => $record->id, 'logical_slot' => 'mov', 'action' => 'Removed',
    ]);
});

test('final archive is idempotent by logical identity and checksum and persists verified metadata', function (): void {
    Storage::fake('local');
    [$user, $record] = replacementTestRecord();
    $path = 'bms-report-movs/final-'.$record->id.'.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nfinal document");
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Final MOV.pdf']);
    $fake = new FakeGoogleDriveArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $fake);
    $archiver = app(FinalDocumentArchiver::class);

    $isFinalAndAuthorized = static fn (): null => null;
    $first = $archiver->archiveFinalDocument($record, 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $isFinalAndAuthorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
    $second = $archiver->archiveFinalDocument($record->fresh(), 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $isFinalAndAuthorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');

    expect($first['status'])->toBe('archived')
        ->and($second['status'])->toBe('archived')
        ->and($first['file_id'])->toBe($second['file_id'])
        ->and($fake->uploadCount)->toBe(1)
        ->and($fake->archiveStatusAtUpload)->toBe('PENDING')
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->where('logical_slot', 'mov')->value('archive_status'))->toBe('ARCHIVED');
    Storage::disk('local')->assertExists($path);
    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->firstOrFail();
    expect($archive->archived_sha256)->toBe(hash('sha256', "%PDF-1.4\nfinal document"))
        ->and($archive->archived_size)->toBe(strlen("%PDF-1.4\nfinal document"))
        ->and($fake->verificationCount)->toBeGreaterThanOrEqual(3);
});

test('a verified records checkpoint remains frozen after the private current document changes', function (): void {
    Storage::fake('local');
    [$user, $record] = replacementTestRecord();
    $originalPath = 'bms-report-movs/checkpoint-original-'.$record->id.'.pdf';
    $laterPath = 'bms-report-movs/checkpoint-later-'.$record->id.'.pdf';
    $originalBytes = "%PDF-1.4\ncheckpoint original";
    $laterBytes = "%PDF-1.4\ncheckpoint later";
    Storage::disk('local')->put($originalPath, $originalBytes);
    $record->update(['mov_file_path' => $originalPath, 'mov_file_name' => 'Checkpoint.pdf']);
    $fake = new FakeGoogleDriveArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $fake);
    $archiver = app(FinalDocumentArchiver::class);
    $authorized = static fn (): null => null;

    $first = $archiver->archiveFinalDocument($record, 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $authorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
    Storage::disk('local')->put($laterPath, $laterBytes);
    $record->update(['mov_file_path' => $laterPath, 'mov_file_name' => 'Later Checkpoint.pdf']);
    $retry = $archiver->archiveFinalDocument($record->fresh(), 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $authorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');

    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->firstOrFail();
    expect($first['status'])->toBe('archived')
        ->and($retry['status'])->toBe('archived')
        ->and($retry['file_id'])->toBe($first['file_id'])
        ->and($fake->uploadCount)->toBe(1)
        ->and($archive->archived_sha256)->toBe(hash('sha256', $originalBytes))
        ->and($archive->archived_sha256)->not->toBe(hash('sha256', $laterBytes));
    Storage::disk('local')->assertExists($laterPath);
});

test('failed Drive verification leaves the private current document untouched', function (): void {
    Storage::fake('local');
    [$user, $record] = replacementTestRecord();
    $path = 'bms-report-movs/final-failure-'.$record->id.'.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nfinal document");
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Final MOV.pdf']);
    $fake = new FakeGoogleDriveArchiveGateway();
    $fake->failVerification = true;
    app()->instance(GoogleDriveArchiveGateway::class, $fake);

    $result = app(FinalDocumentArchiver::class)->archiveFinalDocument($record, 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, static fn (): null => null, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');

    expect($result['status'])->toBe('failed')
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->value('archive_status'))->toBe('FAILED');
    Storage::disk('local')->assertExists($path);
    expect($record->fresh()->mov_file_path)->toBe($path);
});

test('archive metadata persistence failure retries by reconciling the existing remote object without duplicate upload', function (): void {
    Storage::fake('local');
    [$user, $record] = replacementTestRecord();
    $path = 'bms-report-movs/metadata-retry-'.$record->id.'.pdf';
    $bytes = "%PDF-1.4\nmetadata retry";
    Storage::disk('local')->put($path, $bytes);
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Final MOV.pdf']);
    $fake = new FakeGoogleDriveArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $fake);
    $failArchiveCommitOnce = true;
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$failArchiveCommitOnce): void {
        if ($failArchiveCommitOnce
            && str_starts_with(strtolower(ltrim($query->sql)), 'update')
            && str_contains(strtolower($query->sql), 'document_archives')
            && in_array('ARCHIVED', $query->bindings, true)) {
            $failArchiveCommitOnce = false;
            throw new RuntimeException('Injected archive metadata persistence failure');
        }
    });
    $archiver = app(FinalDocumentArchiver::class);
    $finalAndAuthorized = static fn (): null => null;

    $first = $archiver->archiveFinalDocument($record, 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $finalAndAuthorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
    expect($first['status'])->toBe('failed')
        ->and($fake->uploadCount)->toBe(1)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->value('archive_status'))->toBe('FAILED');
    Storage::disk('local')->assertExists($path);

    $retry = $archiver->archiveFinalDocument($record->fresh(), 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $finalAndAuthorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
    expect($retry['status'])->toBe('archived')
        ->and($retry['file_id'])->toBe('fake-drive-1')
        ->and($fake->uploadCount)->toBe(1)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->value('archive_status'))->toBe('ARCHIVED');
    Storage::disk('local')->assertExists($path);
});

test('previously archived checkpoints only refresh availability and remain frozen on every recheck outcome', function (): void {
    Storage::fake('local');
    [$user, $record] = replacementTestRecord();
    $path = 'bms-report-movs/frozen-checkpoint-'.$record->id.'.pdf';
    $bytes = "%PDF-1.4\nfrozen checkpoint";
    Storage::disk('local')->put($path, $bytes);
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Frozen.pdf']);
    $fake = new FakeGoogleDriveArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $fake);
    $archiver = app(FinalDocumentArchiver::class);
    $authorized = static fn (): null => null;
    $created = $archiver->archiveFinalDocument($record, 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $authorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $record->id)->firstOrFail();
    $fileId = $archive->google_drive_file_id;
    $sha256 = $archive->archived_sha256;
    $archivedAt = $archive->archived_at;
    $uploadCount = $fake->uploadCount;

    foreach ([
        ['verified', 'verified', null],
        ['unavailable', 'unavailable', 'NOT_FOUND'],
        ['unknown', 'unknown', 'PROVIDER_ERROR'],
        ['content_mismatch', 'unknown', 'CONTENT_MISMATCH'],
    ] as [$providerResult, $expectedAvailability, $expectedError]) {
        $fake->forcedAvailability = $providerResult;
        $result = $archiver->archiveFinalDocument($record->fresh(), 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $authorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
        $archive->refresh();
        expect($result['status'])->toBe('archived')
            ->and($archive->archive_status)->toBe('ARCHIVED')
            ->and($archive->remote_availability)->toBe($expectedAvailability)
            ->and($archive->last_verification_error_class)->toBe($expectedError)
            ->and($archive->google_drive_file_id)->toBe($fileId)
            ->and($archive->archived_sha256)->toBe($sha256)
            ->and($archive->archived_at->equalTo($archivedAt))->toBeTrue()
            ->and($fake->uploadCount)->toBe($uploadCount);
    }

    Storage::disk('local')->delete($path);
    $fake->forcedAvailability = 'verified';
    $missingLocal = $archiver->archiveFinalDocument($record->fresh(), 'bms', 'mov', 'mov_file_path', 'mov_file_name', $user->id, $authorized, 'shared-folder', archiveTestModule(), 'Conservation Unit', 'CENRO Mati');
    expect($missingLocal['status'])->toBe('archived')
        ->and($archive->fresh()->archive_status)->toBe('ARCHIVED')
        ->and($archive->fresh()->remote_availability)->toBe('verified')
        ->and($archive->fresh()->google_drive_file_id)->toBe($fileId)
        ->and($fake->uploadCount)->toBe($uploadCount);
});
