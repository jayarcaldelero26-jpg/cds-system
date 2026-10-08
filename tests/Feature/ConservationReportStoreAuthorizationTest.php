<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentAttachmentHistory;
use App\Models\DocumentRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => 'CENRO_CDS_FOCAL',
        'office_designated' => 'CENRO Baganga',
        'protected_area_id' => null,
    ]);

    $role = Role::findOrCreate('CENRO CDS Focal Person', 'web');
    $role->syncPermissions([
        Permission::findOrCreate('technical-reports.view', 'web'),
        Permission::findOrCreate('technical-reports.create', 'web'),
        Permission::findOrCreate('technical-reports.update', 'web'),
    ]);
    $this->user->assignRole($role);
    $this->area = ProtectedArea::create([
        'name' => 'Baganga Scoped Area', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $officeId = DB::table('organizational_offices')->where('code', 'cenro_baganga')->value('id');
    DB::table('protected_area_office_assignments')->insert(['protected_area_id' => $this->area->id, 'organizational_office_id' => $officeId, 'assignment_type' => 'supervising', 'assigned_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);});

function focalRegularPambPayload(array $overrides = []): array
{
    return array_merge([
        'protected_area_id' => null,
        'target_office' => '',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-24',
        'date_accomplished' => '2026-08-24',
        'mov' => UploadedFile::fake()->create('regular-pamb.pdf', 10, 'application/pdf'),
    ], $overrides);
}

test('CENRO focal rejects a create form that omits target office', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload(['protected_area_id' => $this->area->id]))
        ->assertSessionHasErrors('target_office')
        ->assertSessionHasErrors('target_office');

// removed obsolete persistence expectation

});

test('CENRO focal cannot submit another target office during save', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
            'protected_area_id' => $this->area->id,
            'target_office' => 'CENRO Mati',
        ]))
        ->assertForbidden();

    expect(ConservationReportSubmission::query()->count())->toBe(0);
});

test('CENRO focal still reaches the regular PAMB page with its own office scope', function (): void {
    $this->actingAs($this->user)
        ->get(route('conservation-reports.index', 'regular_pamb'))
        ->assertOk();
});

test('a future actual activity date is rejected before create writes a report, event, audit, or document', function (string $field, string $conducted, string $accomplished): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $auditCount = DB::table('audit_logs')->count();

    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
            'protected_area_id' => $this->area->id,
            'target_office' => 'CENRO Baganga',
            'date_conducted' => $conducted,
            'date_accomplished' => $accomplished,
        ]))
        ->assertSessionHasErrors($field);

    expect(ConservationReportSubmission::query()->count())->toBe(0)
        ->and(DocumentRoutingEvent::query()->count())->toBe(0)
        ->and(DocumentAttachmentHistory::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    ['date_conducted', '2026-10-09', '2026-10-10'],
    ['date_accomplished', '2026-10-08', '2026-10-09'],
]);

test('an unrelated edit preserves an unchanged legacy future activity date', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Manila'));
    $path = 'conservation-report-movs/legacy-future-activity.pdf';
    Storage::disk('local')->put($path, "%PDF-1.4\nSynthetic legacy future-date report");
    $report = ConservationReportSubmission::query()->create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Baganga', 'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-10-22', 'date_accomplished' => '2026-10-22',
        'mov_file_name' => basename($path), 'mov_file_path' => $path,
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('conservation-reports.update', ['regular_pamb', $report->id]), [
            'protected_area_id' => $this->area->id,
            'target_office' => 'CENRO Baganga',
            'activity_name' => 'Regular PAMB — corrected label',
            'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 1',
            'date_conducted' => '2026-10-22',
            'date_accomplished' => '2026-10-22',
            'remarks' => 'Unrelated field edit.',
        ])
        ->assertRedirect()
    ->assertSessionHasNoErrors();

    expect($report->fresh()->activity_name)->toBe('Regular PAMB — corrected label')
        ->and(CarbonImmutable::parse($report->fresh()->date_conducted)->toDateString())->toBe('2026-10-22')
        ->and(CarbonImmutable::parse($report->fresh()->date_accomplished)->toDateString())->toBe('2026-10-22')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

test('CENRO focal cannot create a submission for a PENRO-managed protected area', function (): void {
    $area = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $penroOfficeId = DB::table('organizational_offices')->where('code', 'penro_davao_oriental')->value('id');
    DB::table('protected_area_office_assignments')->insert(['protected_area_id' => $area->id, 'organizational_office_id' => $penroOfficeId, 'assignment_type' => 'supervising', 'assigned_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'regular_pamb'), focalRegularPambPayload([
            'protected_area_id' => $area->id,
            'target_office' => 'CENRO Baganga',
        ]))
        ->assertForbidden();

    expect(ConservationReportSubmission::query()->where('protected_area_id', $area->id)->exists())
        ->toBeFalse();
});
