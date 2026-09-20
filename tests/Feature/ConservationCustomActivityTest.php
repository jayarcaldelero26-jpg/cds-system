<?php

use App\Models\ConservationReportSubmission;
use App\Models\User;
use App\Models\ProtectedArea;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CENRO_CDS_FOCAL', 'unit_assignment' => null, 'office_designated' => 'CENRO Mati']);
    foreach (['technical-reports.view', 'technical-reports.create', 'technical-reports.update', 'technical-reports.delete'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $this->area = ProtectedArea::create([
        'name' => 'Conservation Required Field Area',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

$officeId = DB::table('organizational_offices')->where('code', 'cenro_mati')->value('id');
    DB::table('protected_area_office_assignments')->insert([
        'protected_area_id' => $this->area->id,
        'organizational_office_id' => $officeId,
        'assignment_type' => 'supervising',
        'assigned_by' => $this->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('a Conservation report stores an activity name outside the registry', function () {
    Storage::fake('public');

    $this->actingAs($this->user)->post(route('conservation-reports.store', 'homestay'), [
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Community-led homestay orientation',
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-08-24',
        'mov' => UploadedFile::fake()->create('custom-activity.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('conservation_report_submissions', [
        'workflow_key' => 'homestay',
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Community-led homestay orientation',
    ]);
});

test('a Conservation report updates to an activity name outside the registry', function () {
    $submission = ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1',
        'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)->put(route('conservation-reports.update', ['workflow' => 'homestay', 'submission' => $submission]), [
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Revised community homestay workshop',
        'date_accomplished' => '2026-08-24',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('conservation_report_submissions', [
        'id' => $submission->id,
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Revised community homestay workshop',
        'date_accomplished' => '2026-08-24',
    ]);
});

test('registry activity defaults remain available as prefill suggestions', function () {
    expect(app(ConservationReportWorkflowRegistry::class)->find('homestay')['default_activity'])
        ->toBe('Training on Homestay Program');
});

test('CEPA custom activities use the progress-report document fallback', function () {
    $calendar = app(BusinessCalendarService::class);
    $report = new ConservationReportSubmission([
        'workflow_key' => 'cepa_plan',
        'activity_name' => 'Locally named CEPA coordination activity',
        'document_type' => 'Progress Report',
        'date_accomplished' => '2026-01-05',
        'date_received_penro' => $calendar->addWorkingDays('2026-01-05', 8, null, BusinessCalendarService::CONSERVATION_WORKING_WEEKDAYS)->toDateString(),
    ]);

    expect($report->deadline_submission)->toBe('2026-01-15')
        ->and($report->timeliness)->toBe('Unsatisfactory');
});

test('CEPA custom activities use the final-report document fallback', function () {
    $calendar = app(BusinessCalendarService::class);
    $report = new ConservationReportSubmission([
        'workflow_key' => 'cepa_plan',
        'activity_name' => 'Locally named CEPA coordination activity',
        'document_type' => 'Final Report',
        'date_accomplished' => '2026-01-05',
        'date_received_penro' => $calendar->addWorkingDays('2026-01-05', 8, null, BusinessCalendarService::CONSERVATION_WORKING_WEEKDAYS)->toDateString(),
    ]);

    expect($report->deadline_submission)->toBe('2026-01-29')
        ->and($report->timeliness)->toBe('Outstanding');
});

test('an official registry activity value continues to save successfully', function () {
    Storage::fake('public');

    $this->actingAs($this->user)->post(route('conservation-reports.store', 'regular_pamb'), [
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-24',
        'date_accomplished' => '2026-08-24',
        'mov' => UploadedFile::fake()->create('regular-pamb.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('conservation_report_submissions', [
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $this->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB',
    ]);
});
