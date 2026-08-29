<?php

use App\Models\BmsRecord;
use App\Models\EngpReportSubmission;
use App\Models\ImeaAssessment;
use App\Models\ManagementPlan;
use App\Models\ManagementPlanProfile;
use App\Models\ManagementPlanType;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

function migrationProtectedArea(): ProtectedArea
{
    $user = \App\Models\User::factory()->create();

    return ProtectedArea::create([
        'name' => 'Attachment Migration Test PA',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

function migrationBmsRecord(string $path): BmsRecord
{
    return BmsRecord::create([
        'protected_area_id' => migrationProtectedArea()->id,
        'monitoring_date' => '2026-08-29',
        'taxonomic_group' => 'Birds',
        'species_scientific_name' => 'Migration test species',
        'attachment' => $path,
    ]);
}

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

test('the protected attachment migration command is registered', function () {
    $this->artisan('list')
        ->expectsOutputToContain('edats:migrate-protected-attachments')
        ->assertExitCode(0);
});

test('default invocation and dry-run do not mutate storage or database paths', function () {
    $record = migrationBmsRecord('bms-attachments/historical.pdf');
    Storage::disk('public')->put($record->attachment, 'historical file');

    $this->artisan('edats:migrate-protected-attachments')
        ->expectsOutputToContain('Mode: DRY RUN')
        ->expectsOutputToContain('Would migrate: 1')
        ->assertExitCode(0);

    Storage::disk('local')->assertMissing($record->attachment);
    Storage::disk('public')->assertExists($record->attachment);
    expect($record->fresh()->attachment)->toBe('bms-attachments/historical.pdf');
});

test('execute copies an active public file, preserves the source, and is idempotent', function () {
    $record = migrationBmsRecord('bms-attachments/historical.pdf');
    Storage::disk('public')->put($record->attachment, 'historical file');

    $this->artisan('edats:migrate-protected-attachments', ['--execute' => true])
        ->expectsOutputToContain('Copied to private: 1')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists($record->attachment);
    Storage::disk('public')->assertExists($record->attachment);

    $this->artisan('edats:migrate-protected-attachments', ['--execute' => true])
        ->expectsOutputToContain('Already private: 1')
        ->expectsOutputToContain('Copied to private: 0')
        ->assertExitCode(0);
});

test('missing, zero-byte, invalid, and collision references are reported safely', function () {
    $missing = migrationBmsRecord('bms-attachments/missing.pdf');
    $zero = migrationBmsRecord('bms-attachments/zero.pdf');
    $invalid = migrationBmsRecord('../outside.pdf');
    $collision = migrationBmsRecord('bms-attachments/collision.pdf');
    Storage::disk('public')->put($zero->attachment, '');
    Storage::disk('public')->put($collision->attachment, 'public version');
    Storage::disk('local')->put($collision->attachment, 'private version');

    $result = $this->artisan('edats:migrate-protected-attachments', ['--execute' => true]);

    $result->expectsOutputToContain('MISSING — SKIPPED')
        ->expectsOutputToContain('ZERO-BYTE — REVIEW')
        ->expectsOutputToContain('INVALID — SKIPPED')
        ->expectsOutputToContain('COLLISION — MANUAL REVIEW')
        ->expectsOutputToContain('Missing: 1')
        ->expectsOutputToContain('Zero-byte: 1')
        ->expectsOutputToContain('Collisions: 1')
        ->assertExitCode(0);

    expect(Storage::disk('local')->get($collision->attachment))->toBe('private version');
    expect($missing->fresh()->attachment)->toBe('bms-attachments/missing.pdf');
});

test('duplicate physical references are copied only once', function () {
    $path = 'bms-attachments/shared.pdf';
    migrationBmsRecord($path);
    migrationBmsRecord($path);
    Storage::disk('public')->put($path, 'shared file');

    $this->artisan('edats:migrate-protected-attachments', ['--execute' => true])
        ->expectsOutputToContain('Copied to private: 1')
        ->expectsOutputToContain('Duplicate references: 1')
        ->assertExitCode(0);
});

test('external ENGP MOV URLs are classified without filesystem access', function () {
    EngpReportSubmission::create([
        'workflow_key' => 'site_visit',
        'office' => 'CENRO Baganga',
        'activity_name' => 'Site Visit',
        'document_type' => 'Quarterly Report',
        'reporting_year' => 2026,
        'period_key' => 'Q1',
        'period_label' => 'Quarter 1',
        'deadline_submission' => '2026-03-10',
        'mov_external_url' => 'https://example.com/mov.pdf',
    ]);

    $this->artisan('edats:migrate-protected-attachments')
        ->expectsOutputToContain('EXTERNAL — NOT MIGRATED')
        ->expectsOutputToContain('External URLs: 1')
        ->expectsOutputToContain('Would migrate: 0')
        ->assertExitCode(0);
});

test('management plan legacy strings and metadata objects are migrated through the registry', function () {
    $area = migrationProtectedArea();
    $type = ManagementPlanType::create(['name' => 'PAMP', 'slug' => 'pamp']);
    $plan = ManagementPlan::create([
        'protected_area_id' => $area->id,
        'management_plan_type_id' => $type->id,
        'plan_type' => 'PAMP',
        'title' => 'Legacy plan',
        'attachments' => ['management-plans/legacy.pdf'],
        'created_by' => $area->created_by,
        'updated_by' => $area->updated_by,
    ]);
    $profile = ManagementPlanProfile::create([
        'management_plan_type_id' => $type->id,
        'protected_area_id' => $area->id,
        'documents' => [['path' => 'management-plan-profiles/profile.pdf', 'original_name' => 'profile.pdf']],
    ]);
    Storage::disk('public')->put($plan->attachments[0], 'legacy plan');
    Storage::disk('public')->put($profile->documents[0]['path'], 'profile document');

    $this->artisan('edats:migrate-protected-attachments')
        ->expectsOutputToContain('Would migrate: 2')
        ->assertExitCode(0);
});

test('IMEA JSON attachment arrays are migrated without changing their database representation', function () {
    $area = migrationProtectedArea();
    $assessment = ImeaAssessment::create([
        'protected_area_id' => $area->id,
        'pamo_name' => 'Test PAMO',
        'assessment_year' => 2026,
        'assessment_period' => 'Q1',
        'attachments' => ['imea-attachments/one.pdf', ['path' => 'imea-attachments/two.jpg', 'name' => 'two.jpg']],
        'created_by' => $area->created_by,
        'updated_by' => $area->updated_by,
    ]);
    Storage::disk('public')->put('imea-attachments/one.pdf', 'one');
    Storage::disk('public')->put('imea-attachments/two.jpg', 'two');

    $this->artisan('edats:migrate-protected-attachments')
        ->expectsOutputToContain('Would migrate: 2')
        ->assertExitCode(0);

    expect($assessment->fresh()->attachments)->toBe(['imea-attachments/one.pdf', ['path' => 'imea-attachments/two.jpg', 'name' => 'two.jpg']]);
});

test('the protected resolver serves a file after migration without rewriting its database path', function () {
    $record = migrationBmsRecord('bms-attachments/resolved.pdf');
    Storage::disk('public')->put($record->attachment, "%PDF-1.4\nresolved");

    $this->artisan('edats:migrate-protected-attachments', ['--execute' => true])->assertExitCode(0);
    $descriptor = app(ProtectedAttachmentService::class)->descriptor('bms-data', $record->fresh(), 'attachment');

    expect($record->fresh()->attachment)->toBe('bms-attachments/resolved.pdf')
        ->and($descriptor['url'])->toContain('/attachments/bms-data/'.$record->id.'/attachment');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('bms.view', 'web'));
    $this->actingAs($viewer)
        ->get($descriptor['url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('retired and orphan directories are excluded from active migration candidates', function () {
    migrationBmsRecord('bms-attachments/active.pdf');
    Storage::disk('public')->put('cds-lawin-monitorings/legacy.pdf', 'legacy');
    Storage::disk('public')->put('lawin-monitorings/legacy.pdf', 'legacy');
    Storage::disk('public')->put('issue-monitorings/legacy.pdf', 'legacy');
    Storage::disk('public')->put('ppa-attachments/legacy.pdf', 'legacy');
    Storage::disk('public')->put('ecotourism-monitorings/legacy.pdf', 'legacy');
    Storage::disk('public')->put('unreferenced/possible-orphan.pdf', 'orphan');

    $this->artisan('edats:migrate-protected-attachments')
        ->expectsOutputToContain('Orphan candidates: 1')
        ->expectsOutputToContain('unreferenced/possible-orphan.pdf')
        ->assertExitCode(0);
});
