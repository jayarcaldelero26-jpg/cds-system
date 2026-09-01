<?php

use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->create(['section' => 'CDS']);

    foreach (['technical-reports.create', 'technical-reports.view', 'bms.create', 'bms.view', 'bams.create', 'bams.view', 'imea.create', 'imea.view'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

function attachmentPrimaryPayload(array $overrides = []): array
{
    return array_merge([
        'activity_name' => 'Primary report upload test',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'semester' => '1st Semester',
        'date_conducted' => '2026-08-01',
        'date_accomplished' => '2026-08-01',
    ], $overrides);
}

test('PAMB Minutes and Resolution accept primary attachments through 100 MB', function (): void {
    foreach ([['Minutes', 'minutes.pdf'], ['Reso', 'resolution.pdf']] as [$documentType, $filename]) {
        $this->actingAs($this->user)->post(route('conservation-reports.store', 'regular_pamb'), attachmentPrimaryPayload([
            'document_type' => $documentType,
            'mov' => UploadedFile::fake()->create($filename, 102400, 'application/pdf'),
        ]))->assertSessionHasNoErrors();
    }

    expect(ConservationReportSubmission::query()->count())->toBe(2);
});

test('PAMB primary attachments over 100 MB are rejected with a document-specific message', function (): void {
    $this->actingAs($this->user)->post(route('conservation-reports.store', 'regular_pamb'), attachmentPrimaryPayload([
        'document_type' => 'Reso',
        'mov' => UploadedFile::fake()->create('resolution.pdf', 102401, 'application/pdf'),
    ]))->assertSessionHasErrors(['mov' => 'The Resolution attachment must not exceed 100 MB.']);
});

test('BAMS, BMS, and IMEA report submissions accept primary attachments through 100 MB', function (): void {
    foreach ([
        ['bams.report-submissions.store', 'bams.pdf'],
        ['bms.report-submissions.store', 'bms.pdf'],
        ['imea.report-submissions.store', 'imea.pdf'],
    ] as [$routeName, $filename]) {
        $this->actingAs($this->user)->post(route($routeName), attachmentPrimaryPayload([
            'document_type' => 'Final Report',
            'mov' => UploadedFile::fake()->create($filename, 102400, 'application/pdf'),
        ]))->assertSessionHasNoErrors();
    }

    expect(BamsReportSubmission::query()->count())->toBe(1)
        ->and(BmsReportSubmission::query()->count())->toBe(1)
        ->and(ImeaReportSubmission::query()->count())->toBe(1);
});

test('IMEA maintenance MOV attachments use a 20 MB limit', function (): void {
    $area = ProtectedArea::create([
        'name' => 'Attachment Policy Protected Area',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $payload = [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Maintenance of Ecotourism Facilities',
        'document_type' => 'Final Report',
        'quarter' => 'Quarter 1',
        'mov' => UploadedFile::fake()->create('maintenance.pdf', 20480, 'application/pdf'),
    ];

    $this->actingAs($this->user)->post(route('imea.maintenance-reports.store'), $payload)->assertSessionHasNoErrors();
    expect(ImeaFacilityMaintenanceReport::query()->count())->toBe(1);

    $this->actingAs($this->user)->post(route('imea.maintenance-reports.store'), [...$payload, 'mov' => UploadedFile::fake()->create('too-large.pdf', 20481, 'application/pdf')])
        ->assertSessionHasErrors(['mov' => 'The MOV attachment must not exceed 20 MB.']);
});
