<?php

use App\Models\BmsRecord;
use App\Models\BmsReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function protectedAttachmentUser(bool $authorized = true): User
{
    $user = User::factory()->create(['section' => $authorized ? 'CENRO_CDS_FOCAL' : 'UNKNOWN', 'unit_assignment' => null, 'office_designated' => 'CENRO Baganga']);
    if ($authorized) {
        $user->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    }

    return $user;
}

function protectedAttachmentRecord(string $path = 'bms-attachments/record.pdf'): BmsRecord
{
    $owner = User::factory()->create();
    $area = ProtectedArea::create([
        'name' => 'Protected Attachment Test PA', 'short_name' => 'BPL',
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

function effectiveBmsAttachmentReport(User $owner, string $path = 'bms-report-movs/effective.pdf'): BmsReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Effective BMS Attachment PA', 'short_name' => 'EBMS',
        'category' => 'Protected Landscape', 'municipality' => 'Baganga',
        'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('name', 'CENRO Baganga')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Effective BMS attachment report', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-09-01',
        'mov_file_name' => basename($path), 'mov_file_path' => $path,
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

function effectiveBmsAttachmentUser(string $section, string $office = 'CENRO Baganga', ?int $protectedAreaId = null): User
{
    return User::factory()->create([
        'section' => $section, 'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'office_designated' => $office, 'protected_area_id' => $protectedAreaId,
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

test('the private disk does not register a public framework storage route', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('storage.local'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('storage.local.upload'))->toBeNull();
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

test('effective Gate-authorized BMS workflow users can access protected BMS report attachments within scope', function () {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $record = effectiveBmsAttachmentReport($owner);
    Storage::disk('local')->put($record->mov_file_path, "%PDF-1.7\neffective bms attachment");

    foreach ([OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::PENRO_FOCAL, OrganizationalAccessService::PENRO_CHIEF] as $section) {
        $user = effectiveBmsAttachmentUser($section, str_starts_with($section, 'CENRO_') ? 'CENRO Baganga' : 'PENRO Davao Oriental');
        expect($user->can('bms.view'))->toBeTrue()
            ->and($user->getAllPermissions()->contains(fn ($permission): bool => $permission->name === 'bms.view'))->toBeFalse();

        $this->actingAs($user)
            ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
});

test('BMS protected attachment keeps scope, global, and cross-source authorization boundaries', function () {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $record = effectiveBmsAttachmentReport($owner, 'bms-report-movs/boundary.pdf');
    Storage::disk('local')->put($record->mov_file_path, "%PDF-1.7\nboundary attachment");

    $wrongOffice = effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    expect($wrongOffice->can('bms.view'))->toBeTrue();
    $this->actingAs($wrongOffice)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertForbidden();

    $pamo = User::factory()->create(['section' => 'PAMO', 'protected_area_id' => $record->protected_area_id]);
    expect($pamo->can('bms.view'))->toBeFalse();
    $this->actingAs($pamo)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertForbidden();

    $crossSource = User::factory()->create(['section' => 'UNKNOWN']);
    $crossSource->givePermissionTo(Permission::findOrCreate('bams.view', 'web'));
    expect($crossSource->can('bms.view'))->toBeFalse();
    $this->actingAs($crossSource)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertForbidden();

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $this->actingAs($admin)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']))
        ->assertOk();

    $this->get('/storage/'.$record->mov_file_path)->assertNotFound();
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

test('legacy public attachment routes and preview URLs are blocked', function () {
    Storage::fake('public');
    Storage::disk('public')->put('bms-attachments/protected.pdf', 'secret');

    $this->get('/storage/bms-attachments/protected.pdf')->assertNotFound();
    $this->actingAs(protectedAttachmentUser())
        ->get('/view-file/bms-attachments/protected.pdf')
        ->assertNotFound();

    $this->get('/issue-monitorings')->assertNotFound();
    $this->get('/lawin-monitorings')->assertNotFound();
    $this->get('/cds-lawin')->assertNotFound();
    $this->get('/ecotourism-monitorings')->assertNotFound();
    $this->get('/program-project-activities')->assertNotFound();
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
