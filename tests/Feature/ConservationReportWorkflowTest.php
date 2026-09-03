<?php

use App\Models\ConservationReportSubmission;
use App\Models\NonWorkingDay;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
    Storage::fake('local');
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['technical-reports.view', 'technical-reports.create', 'technical-reports.update', 'technical-reports.delete'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

test('only the 22 approved conservation workflow keys are registered', function () {
    $registry = app(ConservationReportWorkflowRegistry::class);

    expect($registry->keys())->toHaveCount(22)
        ->toContain('homestay', 'regular_pamb', 'cepa_plan', 'mpan')
        ->and($registry->find('unapproved-workflow'))->toBeNull();
});

test('every registered workflow resolves through the shared report route', function (string $workflow) {
    $this->actingAs($this->user)->get(route('conservation-reports.index', $workflow))->assertOk();
})->with(fn () => app(ConservationReportWorkflowRegistry::class)->keys());

test('representative workflow configurations supply registry-driven selects', function (string $workflow, string $activity, string $document) {
    $config = app(ConservationReportWorkflowRegistry::class)->find($workflow);

    expect($config['period_field'])->toBe('reporting_period')
        ->and($config['activities'])->toContain($activity)
        ->and($config['activity_documents'][$activity])->toContain($document);
})->with([
    ['homestay', 'Training on Homestay Program', 'Progress Report'],
    ['regular_pamb', 'Regular PAMB', 'Minutes'],
    ['cepa_plan', 'Submission of Final CEPA Plan', 'Final Report'],
    ['vtol_operations', 'Comprehensive Insurance (Medium multi rotor)', 'Final Report'],
    ['maintenance_pamo_ecotourism', 'Maintenance of PAMO', 'Report'],
    ['management_effectiveness_assessment', 'Final MEA', 'Final Report'],
    ['mpan', 'MPAN (TAMCMECA/MA-TA-MPAN) Enhancement to different levels of networking (4th Q)', 'Final Report'],
]);
test('Homestay retains its workbook periods and editable activity suggestion', function () {
    $config = app(ConservationReportWorkflowRegistry::class)->find('homestay');

    expect($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3'])
        ->and($config['default_activity'])->toBe('Training on Homestay Program');
});

test('Regular PAMB retains its workbook configuration', function () {
    $config = app(ConservationReportWorkflowRegistry::class)->find('regular_pamb');

    expect($config['label'])->toBe('Regular PAMB Meetings')
        ->and($config['description'])->toBe('Regular PAMB meeting report submission and compliance tracking.')
        ->and($config['default_activity'])->toBe('Regular PAMB')
        ->and($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])
        ->and($config['documents'])->toBe(['Minutes', 'Reso', 'Report']);
});

test('Regular PAMB returns its selected reporting period and protected area filters', function () {
    $area = ProtectedArea::create([
        'name' => 'Pujada Bay Protected Landscape',
        'short_name' => 'Pujada Bay',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $area->id,
        'target_office' => 'PENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-08-24',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('conservation-reports.index', [
            'workflow' => 'regular_pamb',
            'reporting_period' => 'Quarter 1',
            'protected_area_id' => $area->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ConservationReports/Index')
            ->where('filters.reporting_period', 'Quarter 1')
            ->where('filters.protected_area_id', (string) $area->id)
            ->where('workflow.period_field', 'reporting_period')
            ->where('workflow.periods', ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])
            ->count('submissions.data', 1));
});
test('Special PAMB retains its distinct workbook configuration', function () {
    $config = app(ConservationReportWorkflowRegistry::class)->find('special_pamb');

    expect($config['label'])->toBe('Special PAMB Meetings')
        ->and($config['description'])->toBe('Special PAMB meeting report submission and compliance tracking.')
        ->and($config['default_activity'])->toBe('Special PAMB')
        ->and($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])
        ->and($config['documents'])->toBe(['Minutes', 'Reso']);
});
test('Maintenance of Monuments retains its workbook configuration', function () {
    $config = app(ConservationReportWorkflowRegistry::class)->find('maintenance_monuments');

    expect($config['label'])->toBe('Maintenance of Monuments')
        ->and($config['description'])->toBe('Maintenance of monuments report submission and compliance tracking.')
        ->and($config['default_activity'])->toBe('Maintenance of Monuments')
        ->and($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])
        ->and($config['documents'])->toBe(['Progress Report', 'Final Report']);
});
test('the four newly configured report workflows retain exact workbook values', function (string $workflow, string $activity, array $documents) {
    $config = app(ConservationReportWorkflowRegistry::class)->find($workflow);

    expect($config['default_activity'])->toBe($activity)
        ->and($config['documents'])->toBe($documents)
        ->and($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4']);
})->with([
    ['maintenance_buoy', 'Maintenance of Buoys', ['Progress Report', 'Final Report']],
    ['twc_meetings', 'TWC Meeting', ['Report', 'Minutes']],
    ['updating_pamp', 'Updating of PAMP', ['Progress Report', 'Final Report']],
    ['restoration_plan_5_year', 'Preparation of 5-Year Restoration Plan', ['Progress Report', 'Final Report']],
]);
test('the five requested workflows retain their exact activity and document configuration', function (string $workflow, string $activity, array $documents, array $periods, string $periodLabel) {
    $config = app(ConservationReportWorkflowRegistry::class)->find($workflow);

    expect($config['label'])->not->toBeEmpty()
        ->and($config['default_activity'])->toBe($activity)
        ->and($config['activities'])->toContain($activity)
        ->and($config['documents'])->toBe($documents)
        ->and($config['periods'])->toBe($periods)
        ->and($config['period_label'])->toBe($periodLabel)
        ->and($config['activity_documents'][$activity])->toBe($workflow === 'cepa_plan' ? ['Progress Report'] : $documents);
})->with([
    ['additional_bms_site', 'Establishment of additional BMS site (Davao de Oro)', ['Progress Report', 'Final Report'], ['1st Semester', '2nd Semester'], 'Semester'],
    ['cepa_plan', 'CEPA Plan preparation (Analysis/Stocktaking)', ['Progress Report', 'Final Report'], ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], 'Reporting Period'],
    ['vtol_operations', 'Comprehensive Insurance (Medium multi rotor)', ['Final Report'], ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], 'Reporting Period'],
    ['bdfe_terrestrial', 'Development of BDFE for Terrestrial PA', ['Progress Report', 'Final Report'], ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], 'Reporting Period'],
    ['bdfap', 'Identification of Potential BDFAP', ['Inventory Report'], ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'], 'Reporting Period'],
]);

test('CEPA maps preparation stages to progress and final submission to final report', function () {
    $config = app(ConservationReportWorkflowRegistry::class)->find('cepa_plan');

    expect($config['activity_documents'])->toMatchArray([
        'CEPA Plan preparation (Analysis/Stocktaking)' => ['Progress Report'],
        'CEPA Plan preparation (Branding)' => ['Progress Report'],
        'CEPA Plan preparation (Identification of strategies)' => ['Progress Report'],
        'CEPA Plan preparation (Action Planning)' => ['Progress Report'],
        'CEPA Plan preparation (Writing the Communication Plan)' => ['Progress Report'],
        'Submission of Final CEPA Plan' => ['Final Report'],
    ]);
});
test('unapproved workflow keys are rejected', function () {
    $this->actingAs($this->user)->get(route('conservation-reports.index', 'unapproved-workflow'))->assertNotFound();
});

test('a report persists its route workflow key and cannot appear in another workflow', function () {
    Storage::fake('public');
    $this->actingAs($this->user)->post(route('conservation-reports.store', 'homestay'), [
        'target_office' => 'CENRO Mati', 'activity_name' => 'Training on Homestay Program', 'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1', 'date_conducted' => '2026-08-20', 'date_accomplished' => '2026-08-24',
        'mov' => UploadedFile::fake()->create('homestay.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    ConservationReportSubmission::create(['workflow_key' => 'mpan', 'activity_name' => 'MPAN Enhancement', 'date_accomplished' => '2026-08-24']);

    $this->assertDatabaseHas('conservation_report_submissions', ['workflow_key' => 'homestay', 'activity_name' => 'Training on Homestay Program']);
    $this->actingAs($this->user)->get(route('conservation-reports.index', 'homestay'))->assertInertia(fn (Assert $page) => $page->component('ConservationReports/Index')->count('submissions.data', 1)->where('submissions.data.0.workflow_key', 'homestay'));
});

test('meeting PAMB stores independent dates and uses Date Accomplished when present', function () {
    Storage::fake('public');
    $this->actingAs($this->user)->post(route('conservation-reports.store', 'regular_pamb'), [
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-26',
        'date_accomplished' => '2026-09-30',
        'mov' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->latest('id')->firstOrFail();
    $cenroQueue = app(SubmissionTrackingService::class)->queues()[SubmissionTrackingService::CENRO_RELEASE];

    expect($report->date_conducted)->toBe('2026-08-26')
        ->and($report->date_accomplished->toDateString())->toBe('2026-09-30')
        ->and($report->deadline_submission)->toBe('2026-10-13')
        ->and($cenroQueue->pluck('source_id')->all())->toContain($report->id);
});

test('meeting PAMB falls back to Date Conducted when Date Accomplished is blank', function () {
    $report = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb',
        'date_conducted' => '2026-08-26',
        'date_accomplished' => null,
    ]);

    expect($report->deadline_submission)->toBe('2026-09-08');
});

test('all meeting PAMB workflows reject Date Accomplished earlier than Date Conducted', function (string $workflow): void {
    Storage::fake('public');

    $this->actingAs($this->user)->post(route('conservation-reports.store', $workflow), [
        'target_office' => 'CENRO Mati',
        'activity_name' => $workflow === 'twc_meetings' ? 'TWC Meeting' : ucfirst(str_replace('_', ' ', $workflow)),
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-20',
        'date_accomplished' => '2026-08-19',
        'mov' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors(['date_accomplished' => 'Date Accomplished must be on or after Date Conducted.']);

    expect(ConservationReportSubmission::query()->where('workflow_key', $workflow)->exists())->toBeFalse();
})->with(['regular_pamb', 'special_pamb', 'twc_meetings']);

test('meeting PAMB tracker keeps Date Accomplished visible and editable', function (): void {
    $jsx = file_get_contents(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));

    expect($jsx)
        ->toContain('id="bms-report-date-accomplished"')
        ->toContain('Date Accomplished')
        ->not->toContain('{!isMeetingPamb && <CrudSection title="Compliance Basis">');
});

test('Regular PAMB August 19 deadline skips Friday through Sunday and configured August 31 when present', function () {
    $withoutHoliday = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb',
        'date_conducted' => '2026-08-19',
    ]);

    expect($withoutHoliday->deadline_submission)->toBe('2026-09-01');

    NonWorkingDay::create([
        'date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);
    BusinessCalendarService::forgetCache();

    $withHoliday = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb',
        'date_conducted' => '2026-08-19',
    ]);

    expect($withHoliday->deadline_submission)->toBe('2026-09-02');
});

test('conservation reports use the standard seven-working-day deadline and exclude weekends', function () {
    $report = new ConservationReportSubmission(['date_accomplished' => '2026-08-24', 'target_office' => 'CENRO Mati']);
    expect($report->deadline_submission)->toBe('2026-09-02');

    NonWorkingDay::create(['date' => '2026-08-25', 'name' => 'Holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    expect($report->deadline_submission)->toBe('2026-09-03');
});

test('Homestay deadline uses fifteen standard working days', function () {
    $report = new ConservationReportSubmission(['workflow_key' => 'homestay', 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-16');
});

test('Homestay deadline skips an active weekday holiday', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => 'homestay', 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-17');
});
test('Regular PAMB deadline counts seven valid days after Date Conducted', function () {
    $report = new ConservationReportSubmission(['workflow_key' => 'regular_pamb', 'date_conducted' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-08');
});

test('Regular PAMB deadline excludes an active configured weekday holiday', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => 'regular_pamb', 'date_conducted' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-09');
});
test('Special PAMB deadline counts seven valid days after Date Conducted', function () {
    $report = new ConservationReportSubmission(['workflow_key' => 'special_pamb', 'date_conducted' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-08');
});

test('Special PAMB deadline excludes an active configured weekday holiday', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => 'special_pamb', 'date_conducted' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-09');
});
test('Maintenance of Monuments deadline uses the standard seven-working-day rule', function () {
    $report = new ConservationReportSubmission(['workflow_key' => 'maintenance_monuments', 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-04');
});

test('Maintenance of Monuments deadline excludes an active configured weekday holiday', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => 'maintenance_monuments', 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-07');
});
test('non-meeting conservation workflows retain their standard seven-working-day deadline', function (string $workflow) {
    $report = new ConservationReportSubmission(['workflow_key' => $workflow, 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe($workflow === 'maintenance_buoy' ? '2026-09-16' : '2026-09-04');
})->with(['maintenance_buoy', 'updating_pamp', 'restoration_plan_5_year']);

test('non-meeting conservation workflows exclude an active configured weekday holiday', function (string $workflow) {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => $workflow, 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe($workflow === 'maintenance_buoy' ? '2026-09-17' : '2026-09-07');
})->with(['maintenance_buoy', 'updating_pamp', 'restoration_plan_5_year']);

test('TWC meetings use the same effective-date fallback and seven-working-day deadline', function () {
    $report = new ConservationReportSubmission(['workflow_key' => 'twc_meetings', 'date_conducted' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-08');
});

test('TWC meeting deadline skips configured non-working days', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => 'twc_meetings', 'date_conducted' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-09');
});

test('PAMB manual operations keep Date Accomplished and use fifteen working days for the final manual', function () {
    $progress = new ConservationReportSubmission(['workflow_key' => 'updating_pamb_manual', 'activity_name' => 'Workshop / Writeshop', 'date_conducted' => '2026-08-20', 'date_accomplished' => '2026-08-26']);
    $final = new ConservationReportSubmission(['workflow_key' => 'updating_pamb_manual', 'activity_name' => 'Final Updated Manual', 'date_conducted' => '2026-08-20', 'date_accomplished' => '2026-08-26']);

    expect($progress->deadline_submission)->toBe('2026-09-08')
        ->and($final->deadline_submission)->toBe('2026-09-22');
});

test('meeting PAMB Minutes and Reso use the same Date Conducted deadline rule', function (string $workflow, string $document) {
    $report = new ConservationReportSubmission([
        'workflow_key' => $workflow,
        'activity_name' => $workflow === 'twc_meetings' ? 'TWC Meeting' : ucfirst(str_replace('_', ' ', $workflow)),
        'document_type' => $document,
        'date_conducted' => '2026-08-26',
        'date_accomplished' => '2026-08-26',
    ]);

    expect($report->deadline_submission)->toBe('2026-09-08');
})->with([
    ['regular_pamb', 'Minutes'], ['regular_pamb', 'Reso'],
    ['special_pamb', 'Minutes'], ['special_pamb', 'Reso'],
    ['twc_meetings', 'Minutes'], ['twc_meetings', 'Report'],
]);

test('meeting PAMB days complied and timeliness use working days after Date Conducted', function (int $days, string $timeliness) {
    $calendar = app(BusinessCalendarService::class);
    $report = new ConservationReportSubmission([
        'workflow_key' => 'regular_pamb',
        'date_conducted' => '2026-01-05',
        'date_received_penro' => $calendar->addWorkingDays('2026-01-05', $days, null, BusinessCalendarService::PAMB_WORKING_WEEKDAYS)->toDateString(),
    ]);

    expect($report->days_complied)->toBe($days)
        ->and($report->timeliness)->toBe($timeliness);
})->with([[5, 'Outstanding'], [6, 'Very Satisfactory'], [7, 'Satisfactory'], [8, 'Unsatisfactory'], [13, 'Unsatisfactory'], [14, 'Poor']]);

test('meeting PAMB uses Date Accomplished when a legacy row has no Date Conducted', function () {
    $report = new ConservationReportSubmission(['workflow_key' => 'regular_pamb', 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-08');
});

test('final PAMB manual timeliness uses the full Standard A Poor range', function (int $days, string $timeliness) {
    $calendar = app(BusinessCalendarService::class);
    $report = new ConservationReportSubmission([
        'workflow_key' => 'updating_pamb_manual',
        'activity_name' => 'Final Updated Manual',
        'date_accomplished' => '2026-01-05',
        'date_received_penro' => $calendar->addWorkingDays('2026-01-05', $days, null, BusinessCalendarService::PAMB_WORKING_WEEKDAYS)->toDateString(),
    ]);

    expect($report->timeliness)->toBe($timeliness);
})->with([[11, 'Outstanding'], [13, 'Very Satisfactory'], [15, 'Satisfactory'], [29, 'Unsatisfactory'], [90, 'Poor'], [91, 'Poor']]);
test('Additional BMS Site retains semester-only reporting and uses fifteen calendar days', function () {
    $config = app(ConservationReportWorkflowRegistry::class)->find('additional_bms_site');
    $report = new ConservationReportSubmission(['workflow_key' => 'additional_bms_site', 'activity_name' => 'Establishment of additional BMS site (Davao de Oro)', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-08-26']);

    expect($config['period_label'])->toBe('Semester')
        ->and($config['periods'])->toBe(['1st Semester', '2nd Semester'])
        ->and($report->deadline_submission)->toBe('2026-09-10');
});

test('Additional BMS Site counts weekends and ignores working-day holidays', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $report = new ConservationReportSubmission(['workflow_key' => 'additional_bms_site', 'activity_name' => 'Establishment of additional BMS site (Davao de Oro)', 'document_type' => 'Final Report', 'date_accomplished' => '2026-08-26']);

    expect($report->deadline_submission)->toBe('2026-09-10');
});

test('Standard A days complied and timeliness thresholds are calculated by the centralized calendar', function (int $days, string $timeliness) {
    $calendar = app(BusinessCalendarService::class);
    $report = new ConservationReportSubmission(['workflow_key' => 'additional_bms_site', 'activity_name' => 'Establishment of additional BMS site (Davao de Oro)', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-01-05', 'date_received_penro' => CarbonImmutable::parse('2026-01-05')->addDays($days)->toDateString()]);
    expect($report->days_complied)->toBe($days)->and($report->timeliness)->toBe($timeliness);
})->with([[0, 'Outstanding'], [11, 'Outstanding'], [12, 'Very Satisfactory'], [13, 'Very Satisfactory'], [14, 'Satisfactory'], [15, 'Satisfactory'], [16, 'Unsatisfactory'], [29, 'Unsatisfactory'], [30, 'Poor'], [90, 'Poor'], [91, 'No Rating']]);

test('CEPA preparation and final submission resolve different backend rules', function () {
    $preparation = new ConservationReportSubmission(['workflow_key' => 'cepa_plan', 'activity_name' => 'CEPA Plan preparation (Analysis/Stocktaking)', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-08-26']);
    $final = new ConservationReportSubmission(['workflow_key' => 'cepa_plan', 'activity_name' => 'Submission of Final CEPA Plan', 'document_type' => 'Final Report', 'date_accomplished' => '2026-08-26']);

    expect($preparation->deadline_submission)->toBe('2026-09-04')
        ->and($final->deadline_submission)->toBe('2026-09-16');
});

test('CEPA preparation and final submission both skip configured holidays', function () {
    NonWorkingDay::create(['date' => '2026-08-31', 'name' => 'Configured Holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);
    BusinessCalendarService::forgetCache();
    $preparation = new ConservationReportSubmission(['workflow_key' => 'cepa_plan', 'activity_name' => 'CEPA Plan preparation (Branding)', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-08-26']);
    $final = new ConservationReportSubmission(['workflow_key' => 'cepa_plan', 'activity_name' => 'Submission of Final CEPA Plan', 'document_type' => 'Final Report', 'date_accomplished' => '2026-08-26']);

    expect($preparation->deadline_submission)->toBe('2026-09-07')
        ->and($final->deadline_submission)->toBe('2026-09-17');
});

test('CEPA preparation uses Standard B and its final submission uses Standard A', function (int $days, string $preparationTimeliness, string $finalTimeliness) {
    $calendar = app(BusinessCalendarService::class);
    $received = $calendar->addWorkingDays('2026-01-05', $days, null, BusinessCalendarService::STANDARD_WORKING_WEEKDAYS)->toDateString();
    $preparation = new ConservationReportSubmission(['workflow_key' => 'cepa_plan', 'activity_name' => 'CEPA Plan preparation (Action Planning)', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-01-05', 'date_received_penro' => $received]);
    $final = new ConservationReportSubmission(['workflow_key' => 'cepa_plan', 'activity_name' => 'Submission of Final CEPA Plan', 'document_type' => 'Final Report', 'date_accomplished' => '2026-01-05', 'date_received_penro' => $received]);

    expect($preparation->timeliness)->toBe($preparationTimeliness)
        ->and($final->timeliness)->toBe($finalTimeliness);
})->with([[5, 'Outstanding', 'Outstanding'], [6, 'Very Satisfactory', 'Outstanding'], [7, 'Satisfactory', 'Outstanding'], [8, 'Unsatisfactory', 'Outstanding'], [13, 'Unsatisfactory', 'Very Satisfactory'], [14, 'Poor', 'Satisfactory'], [63, 'No Rating', 'Poor'], [91, 'No Rating', 'No Rating']]);

test('VTOL, BDFE, and BDFAP retain quarterly reporting and Standard B seven-day deadlines', function (string $workflow, string $activity, string $document) {
    $config = app(ConservationReportWorkflowRegistry::class)->find($workflow);
    $report = new ConservationReportSubmission(['workflow_key' => $workflow, 'activity_name' => $activity, 'document_type' => $document, 'date_accomplished' => '2026-08-26']);

    expect($config['period_label'])->toBe('Reporting Period')
        ->and($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])
        ->and($report->deadline_submission)->toBe('2026-09-04');
})->with([
    ['vtol_operations', 'Comprehensive Insurance (Medium multi rotor)', 'Final Report'],
    ['bdfe_terrestrial', 'Development of BDFE for Terrestrial PA', 'Progress Report'],
    ['bdfap', 'Identification of Potential BDFAP', 'Inventory Report'],
]);

test('standardized submission statuses are derived from the report lifecycle', function () {
    expect((new ConservationReportSubmission)->submission_status)->toBe('No Activity Conducted')
        ->and((new ConservationReportSubmission(['date_accomplished' => now('Asia/Manila')->toDateString()]))->submission_status)->toBe('Pending Submission by CENRO')
        ->and((new ConservationReportSubmission(['date_accomplished' => '2026-01-05', 'date_report_released_cenro' => '2026-01-05']))->submission_status)->toBe('Pending Receipt by PENRO')
        ->and((new ConservationReportSubmission(['date_accomplished' => '2026-01-05', 'date_report_released_cenro' => '2026-01-05', 'date_received_penro' => '2026-01-06']))->submission_status)->toBe('Pending Regional Endorsement');
});

test('the standard report form requires an attachment and leaves routing dates for submission tracking', function () {
    Storage::fake('public');

    $this->actingAs($this->user)->post(route('conservation-reports.store', 'homestay'), [
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-08-24',
    ])->assertSessionHasErrors(['mov' => 'A report attachment / MOV is required.']);

    $this->actingAs($this->user)->post(route('conservation-reports.store', 'homestay'), [
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-20',
        'date_accomplished' => '2026-08-24',
        'mov' => UploadedFile::fake()->create('mov.pdf', 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::firstOrFail();
    expect($report->date_report_released_cenro)->toBeNull()
        ->and($report->date_received_penro)->toBeNull()
        ->and($report->date_endorsed_regional)->toBeNull()
        ->and($report->mov_file_path)->not->toBeNull();
    Storage::disk('local')->assertExists($report->mov_file_path);
});

test('editing a conservation report preserves its attachment unless an explicit replacement is uploaded', function () {
    Storage::fake('public');
    Storage::disk('public')->put('conservation-report-movs/original.pdf', 'original');
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Training on Homestay Program', 'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-08-24', 'mov_file_name' => 'original.pdf', 'mov_file_path' => 'conservation-report-movs/original.pdf', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);
    $payload = ['activity_name' => 'Training on Homestay Program', 'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 1', 'date_accomplished' => '2026-08-24'];

    $this->actingAs($this->user)->put(route('conservation-reports.update', ['homestay', $report]), $payload)->assertSessionHasNoErrors();
    expect($report->fresh()->mov_file_path)->toBe('conservation-report-movs/original.pdf');

    $this->actingAs($this->user)->post(route('conservation-reports.update', ['homestay', $report]), [...$payload, '_method' => 'put', 'mov' => UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
    $replacement = $report->fresh()->mov_file_path;
    expect($replacement)->not->toBe('conservation-report-movs/original.pdf');
    Storage::disk('public')->assertMissing('conservation-report-movs/original.pdf');
    Storage::disk('local')->assertExists($replacement);
});

test('a crafted hidden attachment removal flag cannot erase an existing conservation MOV', function () {
    Storage::fake('public');
    Storage::disk('public')->put('conservation-report-movs/protected.pdf', 'protected');
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Training on Homestay Program', 'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-08-24', 'mov_file_name' => 'protected.pdf', 'mov_file_path' => 'conservation-report-movs/protected.pdf', 'created_by' => $this->user->id, 'updated_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)->put(route('conservation-reports.update', ['homestay', $report]), [
        'activity_name' => 'Training on Homestay Program', 'document_type' => 'Progress Report', 'reporting_period' => 'Quarter 1',
        'date_accomplished' => '2026-08-24', 'delete_mov' => true,
    ])->assertSessionHasNoErrors();

    expect($report->fresh()->mov_file_path)->toBe('conservation-report-movs/protected.pdf');
    Storage::disk('public')->assertExists('conservation-report-movs/protected.pdf');
});
test('write routes remain protected by the existing technical report permission', function () {
    $user = User::factory()->create(['section' => 'CDS']);
    $this->actingAs($user)->post(route('conservation-reports.store', 'homestay'), ['activity_name' => 'Training on Homestay Program'])->assertForbidden();
});
