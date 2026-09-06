<?php

use App\Models\ConservationReportSubmission;
use App\Models\User;
use App\Services\Dashboard\DashboardMonitoringService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Support\DatePresentationNormalizer;
use DateTimeImmutable;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    $this->user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
});

test('composite conservation coverage dates remain display text while tracking and dashboard safely normalize real dates', function () {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'homestay',
        'activity_name' => 'Composite coverage regression',
        'document_type' => 'Progress Report',
        'date_conducted' => 'August 1, 2, 3, 2026',
        'date_accomplished' => '2026-08-03',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $deadlineBeforePresentation = $report->deadline_submission;

    expect(DatePresentationNormalizer::toDateString('August 1, 2, 3, 2026'))->toBeNull()
        ->and(DatePresentationNormalizer::toDateString('2026-08-03'))->toBe('2026-08-03')
        ->and(DatePresentationNormalizer::toDateString('2026-08-03 14:30:00'))->toBe('2026-08-03')
        ->and(DatePresentationNormalizer::toDateString(new DateTimeImmutable('2026-08-03 14:30:00')))->toBe('2026-08-03');

    $this->actingAs($this->user)->get(route('submission-tracking.index'))->assertOk();

    $trackingRow = app(SubmissionTrackingService::class)->records()
        ->first(fn (array $row): bool => $row['source'] === 'conservation' && $row['source_id'] === $report->id);

    expect($trackingRow)->not->toBeNull()
        ->and($trackingRow['date_conducted'])->toBe('August 1, 2, 3, 2026')
        ->and($trackingRow['date_accomplished'])->toBe('2026-08-03')
        ->and($trackingRow['deadline_submission'])->toBe($deadlineBeforePresentation)
        ->and($report->fresh()->deadline_submission)->toBe($deadlineBeforePresentation);

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();

    $dashboardRow = collect(app(DashboardMonitoringService::class)->overview(['year' => 2026])['rows'])
        ->firstWhere('id', 'conservation-'.$report->id);

    expect($dashboardRow)->not->toBeNull()
        ->and($dashboardRow['date_accomplished'])->toBe('2026-08-03')
        ->and($dashboardRow['deadline_submission'])->toBe($deadlineBeforePresentation);
});
