<?php

use App\Models\ProtectedArea;
use App\Models\TechnicalReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    foreach (['technical-reports.view', 'technical-reports.create', 'technical-reports.update', 'technical-reports.delete'] as $ability) {
        $this->user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    Storage::fake('public');
    $this->area = ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape',
        'short_name' => 'APL',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    Storage::disk('public')->put('technical-reports/existing.pdf', 'existing report');
});

function technicalReportPayload(object $test, array $overrides = []): array
{
    return [...[
        'protected_area_id' => $test->area->id,
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Technical Monitoring Report',
        'report_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_conducted' => 'January 2026',
        'date_accomplished' => '2026-02-01',
        'remarks' => 'Updated remarks',
    ], ...$overrides];
}

function existingTechnicalReport(object $test): TechnicalReport
{
    return TechnicalReport::create([
        ...technicalReportPayload($test),
        'date_report_released_cenro' => '2026-02-03',
        'submission_date' => '2026-02-05',
        'date_endorsed_regional' => '2026-02-06',
        'status' => 'Submitted',
        'attachment' => 'technical-reports/existing.pdf',
        'attachment_original_name' => 'existing.pdf',
        'attachment_mime_type' => 'application/pdf',
        'attachment_size' => 15,
        'created_by' => $test->user->id,
        'updated_by' => $test->user->id,
    ]);
}

test('technical report store and update reject direct routing-date payloads', function () {
    $this->actingAs($this->user)
        ->post(route('technical-reports.store'), [
            ...technicalReportPayload($this),
            'date_received_penro' => '2026-02-05',
            'attachment' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('routing');

    $report = existingTechnicalReport($this);

    $this->actingAs($this->user)
        ->patch(route('technical-reports.update', $report), [
            ...technicalReportPayload($this),
            'date_report_released_cenro' => '2026-02-04',
            'date_received_penro' => '2026-02-06',
            'date_endorsed_regional' => '2026-02-07',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('routing');

    $report->refresh();
    expect($report->date_report_released_cenro?->toDateString())->toBe('2026-02-03')
        ->and($report->submission_date?->toDateString())->toBe('2026-02-05')
        ->and($report->date_endorsed_regional?->toDateString())->toBe('2026-02-06');
});

test('ordinary technical report edits preserve routing dates', function () {
    $report = existingTechnicalReport($this);

    $this->actingAs($this->user)
        ->patch(route('technical-reports.update', $report), technicalReportPayload($this, [
            'activity_name' => 'Updated Technical Monitoring Report',
        ]))
        ->assertRedirect();

    $report->refresh();
    expect($report->activity_name)->toBe('Updated Technical Monitoring Report')
        ->and($report->date_report_released_cenro?->toDateString())->toBe('2026-02-03')
        ->and($report->submission_date?->toDateString())->toBe('2026-02-05')
        ->and($report->date_endorsed_regional?->toDateString())->toBe('2026-02-06');
});
