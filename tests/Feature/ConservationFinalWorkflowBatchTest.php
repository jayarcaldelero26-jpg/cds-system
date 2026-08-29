<?php

use App\Models\ConservationReportSubmission;
use App\Models\NonWorkingDay;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
    $this->user = User::factory()->create(['section' => 'CDS']);
    foreach (['technical-reports.view', 'technical-reports.create', 'technical-reports.update', 'technical-reports.delete'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
});

test('the final Conservation workflow batch has its authoritative periods, documents, defaults, and deadline standards', function (string $workflow, string $defaultActivity, array $documents, int $workingDays, string $standard) {
    $registry = app(ConservationReportWorkflowRegistry::class);
    $config = $registry->find($workflow);

    expect($config['periods'])->toBe(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])
        ->and($config['default_activity'])->toBe($defaultActivity)
        ->and($config['documents'])->toBe($documents)
        ->and($registry->submissionRule($workflow, 'Custom local activity', $documents[0]))->toBe(['working_days' => $workingDays, 'timeliness_standard' => $standard]);
})->with([
    ['maintenance_pa_information_system', 'Maintenance of Protected Area Information System', ['Report'], 7, 'B'],
    ['monitoring_mangroves_corals_seagrass', 'Monitoring of Habitat condition (Mangroves - 1st Q)', ['Report', 'Final Report'], 15, 'A'],
    ['water_quality_monitoring', 'Water Quality Monitoring (1st and 3rd Q)', ['Progress Report', 'Final Report'], 7, 'B'],
    ['mpan', 'MPAN (TAMCMECA/MA-TA-MPAN) Enhancement to different levels of networking (4th Q)', ['Progress Report', 'Final Report'], 7, 'B'],
]);

test('the final Conservation workflow batch accepts custom activity text', function (string $workflow, string $document, string $period) {
    Storage::fake('public');

    $this->actingAs($this->user)->post(route('conservation-reports.store', $workflow), [
        'activity_name' => "Custom local activity for {$workflow}",
        'document_type' => $document,
        'reporting_period' => $period,
        'date_accomplished' => '2026-08-26',
        'mov' => UploadedFile::fake()->create("{$workflow}.pdf", 100, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('conservation_report_submissions', [
        'workflow_key' => $workflow,
        'activity_name' => "Custom local activity for {$workflow}",
    ]);
})->with([
    ['maintenance_pa_information_system', 'Report', 'Quarter 4'],
    ['monitoring_mangroves_corals_seagrass', 'Final Report', 'Quarter 2'],
    ['water_quality_monitoring', 'Progress Report', 'Quarter 2'],
    ['mpan', 'Final Report', 'Quarter 1'],
]);

test('the final Conservation workflow batch skips Friday through Sunday and active holidays', function (string $workflow, string $document, string $ordinaryDeadline, string $holidayDeadline) {
    $report = new ConservationReportSubmission([
        'workflow_key' => $workflow,
        'activity_name' => 'Custom local activity',
        'document_type' => $document,
        'date_accomplished' => '2026-08-26',
    ]);
    expect($report->deadline_submission)->toBe($ordinaryDeadline);

    NonWorkingDay::create([
        'date' => '2026-08-31',
        'name' => 'Configured holiday',
        'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL,
        'is_active' => true,
    ]);
    BusinessCalendarService::forgetCache();

    expect($report->deadline_submission)->toBe($holidayDeadline);
})->with([
    ['maintenance_pa_information_system', 'Report', '2026-09-08', '2026-09-09'],
    ['monitoring_mangroves_corals_seagrass', 'Final Report', '2026-09-22', '2026-09-23'],
    ['water_quality_monitoring', 'Progress Report', '2026-09-08', '2026-09-09'],
    ['mpan', 'Final Report', '2026-09-08', '2026-09-09'],
]);

test('Maintenance, Water Quality, and MPAN use Standard B thresholds', function (string $workflow, string $document) {
    $calendar = app(BusinessCalendarService::class);
    $ratings = [5 => 'Outstanding', 6 => 'Very Satisfactory', 7 => 'Satisfactory', 8 => 'Unsatisfactory', 14 => 'Poor', 63 => 'No Rating'];

    foreach ($ratings as $days => $expected) {
        $report = new ConservationReportSubmission([
            'workflow_key' => $workflow,
            'activity_name' => 'Custom local activity',
            'document_type' => $document,
            'date_accomplished' => '2026-01-05',
            'date_received_penro' => $calendar->addWorkingDays('2026-01-05', $days)->toDateString(),
        ]);

        expect($report->timeliness)->toBe($expected);
    }
})->with([
    ['maintenance_pa_information_system', 'Report'],
    ['water_quality_monitoring', 'Progress Report'],
    ['mpan', 'Final Report'],
]);

test('Monitoring Mangroves, Corals, Seagrass uses Standard A thresholds', function () {
    $calendar = app(BusinessCalendarService::class);
    $ratings = [11 => 'Outstanding', 12 => 'Very Satisfactory', 14 => 'Satisfactory', 16 => 'Unsatisfactory', 30 => 'Poor', 91 => 'No Rating'];

    foreach ($ratings as $days => $expected) {
        $report = new ConservationReportSubmission([
            'workflow_key' => 'monitoring_mangroves_corals_seagrass',
            'activity_name' => 'Custom local activity',
            'document_type' => 'Report',
            'date_accomplished' => '2026-01-05',
            'date_received_penro' => $calendar->addWorkingDays('2026-01-05', $days)->toDateString(),
        ]);

        expect($report->timeliness)->toBe($expected);
    }
});

test('the final Conservation workflow batch rejects document types outside its configuration', function (string $workflow, string $invalidDocument) {
    Storage::fake('public');

    $this->actingAs($this->user)->post(route('conservation-reports.store', $workflow), [
        'activity_name' => 'Custom local activity',
        'document_type' => $invalidDocument,
        'reporting_period' => 'Quarter 1',
        'mov' => UploadedFile::fake()->create('invalid-document.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('document_type');
})->with([
    ['maintenance_pa_information_system', 'Progress Report'],
    ['monitoring_mangroves_corals_seagrass', 'Progress Report'],
    ['water_quality_monitoring', 'Report'],
    ['mpan', 'Report'],
]);
