<?php

use App\Models\BmsRecord;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

function protectedAttachmentResponseBody($response): string
{
    ob_start();
    try {
        $response->baseResponse->sendContent();
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function protectedPambPreviewReport(User $owner, string $workflow, string $path): ConservationReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Preview '.$workflow, 'short_name' => strtoupper(substr(str_replace('_', '', $workflow), 0, 8)),
        'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental',
        'region' => 'Region XI', 'status' => 'Active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('name', 'CENRO Baganga')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return ConservationReportSubmission::create([
        'workflow_key' => $workflow, 'protected_area_id' => $area->id, 'target_office' => 'CENRO Baganga',
        'activity_name' => 'Preview route contract', 'document_type' => 'Minutes', 'reporting_period' => 'Q3 2026',
        'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'mov_file_name' => basename($path), 'mov_file_path' => $path,
        'created_by' => $owner->id, 'updated_by' => $owner->id,
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

test('authorized source and record scope skips the redundant current-routing fallback', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $user = effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $user->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $path = 'bms-report-movs/authorized-primary-preview.pdf';
    $record = effectiveBmsAttachmentReport($owner, $path);
    $bytes = "%PDF-1.7\nAuthorized preview bytes";
    Storage::disk('local')->put($path, $bytes);

    $routingFallbackQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$routingFallbackQueries): void {
        if (str_contains(strtolower($query->sql), 'document_routing_events')) $routingFallbackQueries++;
    });

    $response = $this->actingAs($user)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $record->id, 'attachment' => 'mov']).'?preview=1');
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(protectedAttachmentResponseBody($response))->toBe($bytes)
        ->and($routingFallbackQueries)->toBe(0);
});

test('production security policy keeps same-origin protected document previews available', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production', 'app.debug' => false]);
    Storage::fake('local');
    Storage::fake('public');
    $record = protectedAttachmentRecord();
    Storage::disk('local')->put($record->attachment, "%PDF-1.4\nprivate preview");

    $response = $this->actingAs(protectedAttachmentUser())
        ->get(route('attachments.show', ['source' => 'bms-data', 'record' => $record->id, 'attachment' => 'attachment']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-src 'self' blob:")
        ->toContain("frame-ancestors 'self'")
        ->toContain("object-src 'none'");
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

test('current routing holder can preview and download only the routed official document without source-module CRUD access', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $report = effectiveBmsAttachmentReport($owner, 'bms-report-movs/routed-current.pdf');
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.7\nrouted current official copy");

    $actors = [
        'focal' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga'),
        'chief' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga'),
        'cenro_records' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Baganga'),
        'penro_records' => effectiveBmsAttachmentUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    foreach ($actors as $actor) $actor->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([
        [$actors['focal'], 'forward_to_cenro_chief'],
        [$actors['chief'], 'receive_at_cenro_chief'],
        [$actors['chief'], 'forward_to_cenro_records'],
        [$actors['cenro_records'], 'receive_at_cenro_records'],
        [$actors['cenro_records'], 'forward_to_penro_records'],
        [$actors['penro_records'], 'receive_at_penro_records'],
    ] as [$actor, $action]) $routing->transition($report->fresh(), 'bms', $action, $actor->id);

    $penroRecords = $actors['penro_records'];
    expect($penroRecords->can('bms.view'))->toBeFalse()
        ->and($penroRecords->can('technical-reports.view'))->toBeFalse()
        ->and($penroRecords->can('bms.create'))->toBeFalse()
        ->and($routing->canAccessCurrentDocument($report->fresh(), 'bms', $penroRecords))->toBeTrue();

    $this->actingAs($penroRecords)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('submission-tracking.index', ['source' => 'bms', 'source_id' => $report->id]))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('trackingContext.selected_record.current_document.can_preview', true));
    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?download=1')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="routed-current.pdf"');

    $formerCenroRecordsHolder = $actors['cenro_records'];
    expect($formerCenroRecordsHolder->can('bms.view'))->toBeFalse()
        ->and($routing->canAccessCurrentDocument($report->fresh(), 'bms', $formerCenroRecordsHolder))->toBeFalse();
    $this->actingAs($formerCenroRecordsHolder)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?preview=1')
        ->assertForbidden();
    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?download=1')
        ->assertForbidden();
    $this->get(route('submission-tracking.index', ['source' => 'bms', 'source_id' => $report->id]))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('trackingContext.selected_record.current_document.can_preview', false));

    $nonHolder = effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $nonHolder->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $this->actingAs($nonHolder)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))
        ->assertForbidden();

    auth()->logout();
    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']))
        ->assertRedirect(route('login'));
});

test('Conservation PENRO Records holder can preview and download its current official report copy', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $area = ProtectedArea::create([
        'name' => 'Conservation Routed Document PA', 'short_name' => 'CRD',
        'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental',
        'region' => 'Region XI', 'status' => 'Active', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('name', 'CENRO Baganga')->value('id'),
        'assignment_type' => 'supervising',
    ]);
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'maintenance_pa_information_system', 'protected_area_id' => $area->id,
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Maintenance of Protected Area Information System',
        'document_type' => 'Report', 'date_accomplished' => '2026-09-01', 'created_by' => $owner->id,
        'updated_by' => $owner->id, 'mov_file_name' => 'current-maintenance-report.pdf',
        'mov_file_path' => 'conservation-report-movs/current-maintenance-report.pdf',
    ]);
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.7\ncurrent conservation report");
    $actors = [
        'focal' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga'),
        'chief' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga'),
        'cenro_records' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Baganga'),
        'penro_records' => effectiveBmsAttachmentUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
    ];
    foreach ($actors as $actor) $actor->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([
        [$actors['focal'], 'forward_to_cenro_chief'], [$actors['chief'], 'receive_at_cenro_chief'],
        [$actors['chief'], 'forward_to_cenro_records'], [$actors['cenro_records'], 'receive_at_cenro_records'],
        [$actors['cenro_records'], 'forward_to_penro_records'], [$actors['penro_records'], 'receive_at_penro_records'],
    ] as [$actor, $action]) $routing->transition($report->fresh(), 'conservation', $action, $actor->id);

    $holder = $actors['penro_records'];
    expect($holder->can('technical-reports.view'))->toBeFalse()
        ->and($routing->canAccessCurrentDocument($report->fresh(), 'conservation', $holder))->toBeTrue();
    $this->actingAs($holder)
        ->get(route('attachments.show', ['source' => 'conservation-report', 'record' => $report->id, 'attachment' => 'mov']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('attachments.show', ['source' => 'conservation-report', 'record' => $report->id, 'attachment' => 'mov']).'?download=1')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="current-maintenance-report.pdf"');
});

test('shared upload dropzone uses an SVG icon and contains no mojibake glyphs', function (): void {
    $dropzone = file_get_contents(resource_path('js/Components/Attachments/AttachmentDropzone.jsx'));
    $dateFormatters = file_get_contents(resource_path('js/Utils/dateFormatters.js'));
    expect($dropzone)->toContain('<svg')
        ->and($dropzone)->not->toContain(json_decode('"\\u00e2\\u2020\\u00a5"'))
        ->and($dropzone)->not->toContain(json_decode('"\\u00c3\\u2014"'))
        ->and($dropzone)->not->toContain(json_decode('"\\u00c2\\u00b7"'))
        ->and($dateFormatters)->not->toContain(json_decode('"\\u00e2\\u20ac\\u201d"'));
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

test('approved active global administrators receive real protected preview bytes for PAMB and ENGP sources', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $records = [];
    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $path = 'conservation-report-movs/'.$workflow.'-preview.pdf';
        $records[] = ['conservation-report', protectedPambPreviewReport($owner, $workflow, $path), 'mov', "%PDF-1.7\n{$workflow} official bytes"];
    }
    $engpPath = 'engp-report-movs/site-visit-preview.pdf';
    $engp = \App\Models\EngpReportSubmission::create([
        'workflow_key' => 'site_visit', 'office' => 'CENRO Baganga', 'section_name' => 'NGP',
        'activity_name' => 'Preview endpoint test', 'document_type' => 'Quarterly Report',
        'reporting_year' => 2026, 'period_key' => 'Q3', 'period_label' => 'Quarter 3',
        'deadline_submission' => '2026-10-15', 'mov_file_path' => $engpPath,
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    $records[] = ['engp-report', $engp, 'mov', "%PDF-1.7\nENGP official bytes"];

    foreach ($records as [$source, $record, $key, $bytes]) {
        Storage::disk('local')->put($record->getAttribute($source === 'engp-report' ? 'mov_file_path' : 'mov_file_path'), $bytes);
    }

    foreach (['Super Admin', 'CDS Admin'] as $roleName) {
        $admin = User::factory()->create(['is_active' => true, 'is_approved' => true]);
        $admin->assignRole(Role::findOrCreate($roleName, 'web'));
        expect(app(OrganizationalAccessService::class)->isGlobal($admin))->toBeTrue();

        foreach ($records as [$source, $record, $key, $bytes]) {
            $response = $this->actingAs($admin)->get(route('attachments.show', [$source, $record->id, $key]).'?preview=1');
            $response->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'inline; filename="'.basename($record->mov_file_path).'"')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            expect(protectedAttachmentResponseBody($response))->toBe($bytes);
        }
    }
});

test('live CENRO focal actors receive protected PAMB preview bytes for each meeting workflow', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $focal = effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $records = [];
    foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
        $path = 'conservation-report-movs/'.$workflow.'-focal.pdf';
        $bytes = "%PDF-1.7\n{$workflow} focal preview";
        $record = protectedPambPreviewReport($owner, $workflow, $path);
        Storage::disk('local')->put($path, $bytes);
        $records[] = [$record, $bytes];
    }

    foreach ($records as [$record, $bytes]) {
        expect($focal->can('technical-reports.view'))->toBeTrue()
            ->and(app(OrganizationalAccessService::class)->canViewSubmissionAttachment($focal, $record))->toBeTrue();
        $response = $this->actingAs($focal)
            ->get(route('attachments.show', ['source' => 'conservation-report', 'record' => $record->id, 'attachment' => 'mov']).'?preview=1');
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(protectedAttachmentResponseBody($response))->toBe($bytes);
    }
});

test('PENRO TSD current receipt holder can preview while Office of the PENRO is denied before receipt', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $bytes = "%PDF-1.7\nTSD receipt-stage preview";
    $report = effectiveBmsAttachmentReport($owner, 'bms-report-movs/tsd-receipt-preview.pdf');
    Storage::disk('local')->put($report->mov_file_path, $bytes);
    $actors = [
        'focal' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga'),
        'chief' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Baganga'),
        'cenro_records' => effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Baganga'),
        'penro_records' => effectiveBmsAttachmentUser(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
        'office' => effectiveBmsAttachmentUser(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'),
        'tsd' => effectiveBmsAttachmentUser(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
    ];
    foreach ($actors as $actor) $actor->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));

    $routing = app(\App\Services\SubmissionTracking\DocumentRoutingTransitionService::class);
    foreach ([
        [$actors['focal'], 'forward_to_cenro_chief'],
        [$actors['chief'], 'receive_at_cenro_chief'],
        [$actors['chief'], 'forward_to_cenro_records'],
        [$actors['cenro_records'], 'receive_at_cenro_records'],
        [$actors['cenro_records'], 'forward_to_penro_records'],
        [$actors['penro_records'], 'receive_at_penro_records'],
        [$actors['penro_records'], 'forward_to_office_penro'],
        [$actors['office'], 'receive_at_office_penro'],
        [$actors['office'], 'assign_to_tsd_chief'],
    ] as [$actor, $action]) $routing->transition($report->fresh(), 'bms', $action, $actor->id);

    $url = route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?preview=1';
    expect($routing->canAccessCurrentDocument($report->fresh(), 'bms', $actors['office']))->toBeFalse()
        ->and($routing->canAccessCurrentDocument($report->fresh(), 'bms', $actors['tsd']))->toBeTrue();
    $this->actingAs($actors['office'])->get($url)->assertForbidden();

    $response = $this->actingAs($actors['tsd'])->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(protectedAttachmentResponseBody($response))->toBe($bytes);
});

test('inactive and unapproved global preview accounts are redirected before protected bytes are served', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $report = effectiveBmsAttachmentReport($owner, 'bms-report-movs/account-state.pdf');
    Storage::disk('local')->put($report->mov_file_path, "%PDF-1.7\nprotected account-state bytes");

    $unapproved = User::factory()->create(['is_active' => true, 'is_approved' => false]);
    $unapproved->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $this->actingAs($unapproved)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?preview=1')
        ->assertRedirect(route('login'))
        ->assertSessionHas('pending_approval', true);

    $inactive = User::factory()->create(['is_active' => false, 'is_approved' => true]);
    $inactive->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->actingAs($inactive)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?preview=1')
        ->assertRedirect(route('login'))
        ->assertSessionHas('account_inactive', true);

    $this->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?preview=1')
        ->assertRedirect(route('login'));
});

test('current preview returns not found rather than reading an archived copy when its local file is missing', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $report = effectiveBmsAttachmentReport($owner, 'bms-report-movs/missing-local.pdf');
    $actor = effectiveBmsAttachmentUser(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Baganga');
    $actor->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    \App\Models\DocumentArchive::query()->create([
        'source_type' => 'bms', 'source_id' => $report->id, 'logical_slot' => 'mov',
        'google_drive_file_id' => 'must-not-be-read-for-preview', 'google_drive_folder_id' => 'private-folder',
        'archived_sha256' => hash('sha256', "%PDF-1.7\narchived"), 'archived_size' => strlen("%PDF-1.7\narchived"),
        'archived_at' => now(), 'archived_by' => $owner->id, 'archive_status' => 'ARCHIVED',
        'original_filename' => 'Archived copy.pdf',
    ]);
    $gateway = Mockery::mock(\App\Services\Archive\GoogleDriveArchiveGateway::class);
    $gateway->shouldNotReceive('retrieve');
    $gateway->shouldNotReceive('upload');
    $gateway->shouldNotReceive('replace');
    app()->instance(\App\Services\Archive\GoogleDriveArchiveGateway::class, $gateway);

    $this->actingAs($actor)
        ->get(route('attachments.show', ['source' => 'bms-report', 'record' => $report->id, 'attachment' => 'mov']).'?preview=1')
        ->assertNotFound();
});
