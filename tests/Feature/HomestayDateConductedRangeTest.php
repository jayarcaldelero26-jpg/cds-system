<?php

use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\DateConductedRangeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->localStorageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cds-homestay-range-'.bin2hex(random_bytes(8));
    config(['filesystems.disks.local.root' => $this->localStorageRoot]);
    Storage::forgetDisk('local');

    $this->user = User::factory()->create([
        'section' => 'CENRO_CDS_FOCAL',
        'unit_assignment' => null,
        'office_designated' => 'CENRO Mati',
    ]);

    foreach (['technical-reports.view', 'technical-reports.create', 'technical-reports.update'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $this->area = ProtectedArea::create([
        'name' => 'Homestay Date Range PA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
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

afterEach(function (): void {
    Storage::forgetDisk('local');
    if (isset($this->localStorageRoot) && File::isDirectory($this->localStorageRoot)) {
        File::deleteDirectory($this->localStorageRoot);
    }
});

function homestayRangePayload(array $overrides = []): array
{
    return [
        'protected_area_id' => test()->area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Training on Homestay Program',
        'document_type' => 'Progress Report',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-05-12',
        'date_conducted_ranges' => [['from' => '2026-05-12', 'to' => '2026-05-14']],
        'date_accomplished' => '2026-05-20',
        'mov' => UploadedFile::fake()->create('homestay-range.pdf', 20, 'application/pdf'),
        ...$overrides,
    ];
}

test('Homestay exposes the shared BMS range picker and structured range payload contract', function (): void {
    $this->actingAs($this->user)
        ->get(route('conservation-reports.index', 'homestay'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('ConservationReports/Index')
            ->where('workflow.date_conducted_ranges_enabled', true)
            ->where('workflow.date_accomplished_required', true));

    $tracker = File::get(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));
    $dateRangePicker = File::get(resource_path('js/Components/DateRangePicker.jsx'));
    $formatting = app(DateConductedRangeService::class)->display([['from' => '2026-05-12', 'to' => '2026-05-14']]);

    expect($tracker)
        ->toContain('dateRangeEnabled = dateConductedRangesEnabled || Boolean(workflowConfig?.date_conducted_ranges_enabled)')
        ->toContain('<DateRangePicker id={\'reportsubmissiontracker-date-conducted-range-\' + index}')
        ->toContain('rangesFromRecord(report.date_conducted_ranges, report.date_conducted)')
        ->toContain('compactDateConductedRanges(data.date_conducted_ranges)')
        ->toContain("date_conducted: dateRangeEnabled && ranges.length > 0 ? ranges[0].from : data.date_conducted")
        ->and($dateRangePicker)->toContain('disabled={!draft.from || !draft.to}')
        ->and($formatting)->toBe('May 12-14, 2026');
});

test('Homestay stores the structured conducted range and presents it with the BMS compact formatter', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'homestay'), homestayRangePayload())
        ->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->where('workflow_key', 'homestay')->firstOrFail();

    expect($report->date_conducted)->toBe('2026-05-12')
        ->and($report->date_conducted_ranges)->toBe([['from' => '2026-05-12', 'to' => '2026-05-14']])
        ->and($report->date_accomplished->toDateString())->toBe('2026-05-20');

    $this->actingAs($this->user)
        ->get(route('conservation-reports.index', 'homestay'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('ConservationReports/Index')
            ->where('submissions.data.0.date_conducted_display', 'May 12-14, 2026')
            ->where('submissions.data.0.date_conducted_ranges.0.from', '2026-05-12')
            ->where('submissions.data.0.date_conducted_ranges.0.to', '2026-05-14')
            ->where('submissions.data.0.date_accomplished', '2026-05-20'));
});

test('Homestay update persists both range endpoints without merging Date Accomplished', function (): void {
    $this->actingAs($this->user)
        ->post(route('conservation-reports.store', 'homestay'), homestayRangePayload())
        ->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->where('workflow_key', 'homestay')->firstOrFail();
    $payload = homestayRangePayload([
        'date_conducted' => '2026-06-23',
        'date_conducted_ranges' => [['from' => '2026-06-23', 'to' => '2026-06-24']],
        'date_accomplished' => '2026-06-30',
        'mov' => null,
    ]);

    $this->actingAs($this->user)
        ->put(route('conservation-reports.update', ['homestay', $report]), $payload)
        ->assertSessionHasNoErrors();

    $report->refresh();
    expect($report->date_conducted)->toBe('2026-06-23')
        ->and($report->date_conducted_ranges)->toBe([['from' => '2026-06-23', 'to' => '2026-06-24']])
        ->and($report->date_accomplished->toDateString())->toBe('2026-06-30');
});

test('Homestay requires valid start and end dates and rejects reversed ranges', function (): void {
    $invalidRanges = [
        [['from' => '2026-05-12', 'to' => '']],
        [['from' => '2026-02-30', 'to' => '2026-03-01']],
        [['from' => '2026-05-14', 'to' => '2026-05-12']],
    ];

    foreach ($invalidRanges as $range) {
        $this->actingAs($this->user)
            ->post(route('conservation-reports.store', 'homestay'), homestayRangePayload(['date_conducted_ranges' => $range]))
            ->assertSessionHasErrors();
    }

    expect(ConservationReportSubmission::query()->where('workflow_key', 'homestay')->count())->toBe(0);
});
