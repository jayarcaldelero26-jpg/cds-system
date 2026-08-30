<?php

use App\Models\BmsRecord;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

function protectedAttachmentUser(bool $authorized = true): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    if ($authorized) {
        $user->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    }

    return $user;
}

function protectedAttachmentRecord(string $path = 'bms-attachments/record.pdf'): BmsRecord
{
    $owner = User::factory()->create();
    $area = ProtectedArea::create([
        'name' => 'Protected Attachment Test PA',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    return BmsRecord::create([
        'protected_area_id' => $area->id,
        'monitoring_date' => '2026-08-29',
        'taxonomic_group' => 'Birds',
        'species_scientific_name' => 'Testus example',
        'attachment' => $path,
    ]);
}

test('the protected attachment registry contains only active attachment sources', function () {
    $sources = array_keys(app(ProtectedAttachmentService::class)->registry());
    expect($sources)->toBe([
        'conservation-report',
        'bms-data',
        'bms-report',
        'bams-report',
        'imea-data',
        'imea-report',
        'imea-maintenance',
        'aws',
        'management-plan',
        'management-plan-profile',
        'technical-report',
        'engp-report',
        'ipaf-management',
        'ipaf-revenue',
    ]);
    expect($sources)->not->toContain('lawin-monitoring');
});

test('protected attachments require source permission and serve only the resolved record file', function () {
    Storage::fake('local');
    Storage::fake('public');
    $record = protectedAttachmentRecord();
    Storage::disk('local')->put($record->attachment, "%PDF-1.4\nprivate document");

    $this->actingAs(protectedAttachmentUser())
        ->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs(protectedAttachmentUser(false))
        ->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']))
        ->assertForbidden();

    $this->actingAs(protectedAttachmentUser())
        ->get(route('attachments.show', ['source' => 'unknown-source', 'record' => $record->id, 'attachment' => 'attachment']))
        ->assertNotFound();

    $this->actingAs(protectedAttachmentUser())
        ->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'not-the-registered-key']))
        ->assertNotFound();
});

test('protected attachments deny unauthenticated requests', function () {
    Storage::fake('local');
    Storage::fake('public');
    $record = protectedAttachmentRecord();
    Storage::disk('local')->put($record->attachment, "%PDF-1.4\nprivate document");

    $this->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']))
        ->assertRedirect(route('login'));
});

test('protected attachments support historical public files without exposing a filesystem path', function () {
    Storage::fake('local');
    Storage::fake('public');
    $record = protectedAttachmentRecord('bms-attachments/historical.pdf');
    Storage::disk('public')->put($record->attachment, 'historical document');

    $response = $this->actingAs(protectedAttachmentUser())
        ->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']));

    $response->assertOk();
    $descriptor = app(ProtectedAttachmentService::class)->descriptor('bms-data', $record, 'attachment');
    expect($descriptor)->toMatchArray(['key' => 'attachment', 'external' => false]);
    expect($descriptor)->not->toHaveKey('path');
});

test('new active attachment uploads use the private disk and record-aware URL', function () {
    Storage::fake('local');
    Storage::fake('public');
    $service = app(ProtectedAttachmentService::class);
    $path = $service->store(UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'), 'bms-data');

    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
    expect($path)->toStartWith('bms-attachments/');
});

test('protected attachment responses reject missing files and path traversal attempts', function () {
    Storage::fake('local');
    Storage::fake('public');
    $record = protectedAttachmentRecord('bms-attachments/missing.pdf');
    $user = protectedAttachmentUser();

    $this->actingAs($user)
        ->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']))
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/attachments/bms-data/'.$record->id.'/../attachment')
        ->assertNotFound();
});

test('protected preview responses use inline headers for pdf and images and attachment fallback for docx', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = protectedAttachmentUser();

    foreach ([
        ['bms-attachments/preview.pdf', "%PDF-1.4\npreview", 'inline'],
        ['bms-attachments/preview.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='), 'inline'],
        ['bms-attachments/preview.docx', "not-a-real-docx", 'attachment'],
    ] as [$path, $contents, $disposition]) {
        $record = protectedAttachmentRecord($path);
        Storage::disk('local')->put($path, $contents);
        $response = $this->actingAs($user)->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']));
        $response->assertOk()->assertHeader('Content-Disposition', $disposition.'; filename="'.basename($path).'"');
    }
});
