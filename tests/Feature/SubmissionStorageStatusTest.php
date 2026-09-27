<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentArchive;
use App\Models\User;
use App\Services\SubmissionTracking\SubmissionStorageStatusPresenter;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function storageStatusUser(string $role): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate($role, 'web'));
    return $user;
}

function storageStatusRecord(User $user): BmsReportSubmission
{
    return BmsReportSubmission::query()->create([
        'semester' => '1st Semester',
        'date_accomplished' => '2026-09-25',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

test('Super Admin receives storage indicators while ordinary CDS Admin does not', function (): void {
    Storage::fake('local');
    $super = storageStatusUser('Super Admin');
    $record = storageStatusRecord($super);
    $path = 'current-documents/bms/'.$record->id.'/mov/current.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 current');
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Current.pdf']);

    $this->actingAs($super);
    $this->get(route('submission-tracking.index', ['source' => 'bms', 'source_id' => $record->id]))
        ->assertInertia(fn ($page) => $page->where('trackingContext.selected_record.storage_status', [
            'hostinger' => ['state' => 'stored', 'label' => 'Stored'],
            'google_drive' => ['state' => 'not_archived', 'label' => 'Not Archived Yet'],
        ]));

    $admin = storageStatusUser('CDS Admin');
    $this->actingAs($admin);
    $this->get(route('submission-tracking.index', ['source' => 'bms', 'source_id' => $record->id]))
        ->assertInertia(fn ($page) => $page->where('trackingContext.selected_record', fn ($selected) => $selected->get('storage_status', '__missing__') === '__missing__'));
});

test('storage indicators distinguish current-file states and archive states without Drive calls', function (): void {
    Storage::fake('local');
    $super = storageStatusUser('Super Admin');
    $record = storageStatusRecord($super);
    $presenter = app(SubmissionStorageStatusPresenter::class);

    expect($presenter->present('bms', $record)['hostinger']['state'])->toBe('none');

    $missingPath = 'current-documents/bms/'.$record->id.'/mov/missing.pdf';
    $record->update(['mov_file_path' => $missingPath, 'mov_file_name' => 'Missing.pdf']);
    expect($presenter->present('bms', $record->fresh())['hostinger']['state'])->toBe('missing');

    Storage::disk('local')->put($missingPath, '%PDF-1.4 current');
    expect($presenter->present('bms', $record->fresh())['hostinger']['state'])->toBe('stored')
        ->and($presenter->present('bms', $record->fresh())['google_drive']['state'])->toBe('not_archived');

    DocumentArchive::query()->create(['source_type' => 'bms', 'source_id' => $record->id, 'logical_slot' => 'mov', 'archive_status' => 'PENDING']);
    expect($presenter->present('bms', $record->fresh())['google_drive']['state'])->toBe('pending');

    $archive = DocumentArchive::query()->first();
    $archive->update(['archive_status' => 'ARCHIVED', 'google_drive_file_id' => 'drive-checkpoint-1']);
    $archive->update(['remote_availability' => 'verified', 'last_verified_at' => now()]);
    expect($presenter->present('bms', $record->fresh())['google_drive']['state'])->toBe('archived');

    Storage::disk('local')->delete($missingPath);
    expect($presenter->present('bms', $record->fresh()))->toMatchArray([
        'hostinger' => ['state' => 'missing', 'label' => 'Missing'],
        'google_drive' => ['state' => 'archived', 'label' => 'Archived'],
    ]);

    $archive->update(['archive_status' => 'FAILED', 'google_drive_file_id' => null]);
    expect($presenter->present('bms', $record->fresh())['google_drive']['state'])->toBe('failed');
});

test('later current-file replacement leaves the checkpoint indicator archived', function (): void {
    Storage::fake('local');
    $super = storageStatusUser('Super Admin');
    $record = storageStatusRecord($super);
    $first = 'current-documents/bms/'.$record->id.'/mov/first.pdf';
    $later = 'current-documents/bms/'.$record->id.'/mov/later.pdf';
    Storage::disk('local')->put($first, '%PDF-1.4 first');
    $record->update(['mov_file_path' => $first, 'mov_file_name' => 'First.pdf']);
    DocumentArchive::query()->create(['source_type' => 'bms', 'source_id' => $record->id, 'logical_slot' => 'mov', 'archive_status' => 'ARCHIVED', 'remote_availability' => 'verified', 'google_drive_file_id' => 'drive-checkpoint-1']);

    Storage::disk('local')->put($later, '%PDF-1.4 later');
    $record->update(['mov_file_path' => $later, 'mov_file_name' => 'Later.pdf']);

    expect(app(SubmissionStorageStatusPresenter::class)->present('bms', $record->fresh()))->toMatchArray([
        'hostinger' => ['state' => 'stored', 'label' => 'Stored'],
        'google_drive' => ['state' => 'archived', 'label' => 'Archived'],
    ]);
});

test('archived lifecycle remains separate from unavailable remote availability', function (): void {
    Storage::fake('local');
    $super = storageStatusUser('Super Admin');
    $record = storageStatusRecord($super);
    $path = 'current-documents/bms/'.$record->id.'/mov/deleted.pdf';
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Deleted.pdf']);
    $archive = DocumentArchive::query()->create([
        'source_type' => 'bms', 'source_id' => $record->id, 'logical_slot' => 'mov',
        'archive_status' => 'ARCHIVED', 'remote_availability' => 'unavailable',
        'last_verified_at' => now(), 'last_verification_error_class' => 'NOT_FOUND', 'google_drive_file_id' => 'deleted-object',
    ]);

    expect(app(SubmissionStorageStatusPresenter::class)->present('bms', $record->fresh()))->toMatchArray([
        'hostinger' => ['state' => 'missing', 'label' => 'Missing'],
        'google_drive' => ['state' => 'unavailable', 'label' => 'Archive Unavailable'],
    ])->and($archive->fresh()->archive_status)->toBe('ARCHIVED');
});

test('Full Details storage indicator is a read-only React section with no Drive operation', function (): void {
    $index = file_get_contents(resource_path('js/Pages/SubmissionTracking/Index.jsx'));
    $service = file_get_contents(app_path('Services/SubmissionTracking/SubmissionStorageStatusPresenter.php'));

    expect($index)->toContain('details?.storage_status')
        ->and($index)->toContain('Document Storage')
        ->and($service)->not->toContain('GoogleDriveArchiveGateway')
        ->and($service)->not->toContain('upload(')
        ->and($service)->not->toContain('retrieve(');
});

test('missing current document is detected without changing the private storage inventory', function (): void {
    Storage::fake('local');
    $super = storageStatusUser('Super Admin');
    $record = storageStatusRecord($super);
    $path = 'current-documents/bms/'.$record->id.'/mov/historical-missing.pdf';
    $record->update(['mov_file_path' => $path, 'mov_file_name' => 'Historical.pdf']);
    DocumentArchive::query()->create([
        'source_type' => 'bms', 'source_id' => $record->id, 'logical_slot' => 'mov',
        'archive_status' => 'ARCHIVED', 'google_drive_file_id' => 'verified-archive-id',
        'archived_sha256' => hash('sha256', '%PDF-1.4 archived'), 'archived_size' => strlen('%PDF-1.4 archived'),
        'original_filename' => 'Historical.pdf',
    ]);
    $before = Storage::disk('local')->allFiles();

    expect(app(SubmissionStorageStatusPresenter::class)->present('bms', $record->fresh()))->toMatchArray([
        'hostinger' => ['state' => 'missing', 'label' => 'Missing'],
        'google_drive' => ['state' => 'archived', 'label' => 'Archived'],
    ])->and(Storage::disk('local')->allFiles())->toBe($before);
});
