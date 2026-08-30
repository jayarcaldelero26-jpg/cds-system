<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Compliance\OverdueReportService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => 'CDS']);
    $this->mhrws = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $this->normalArea = ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape',
        'category' => 'Protected Landscape',
        'municipality' => 'Baganga',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
});

function mhrwsConservationReport(object $test, array $overrides = []): ConservationReportSubmission
{
    return ConservationReportSubmission::create(array_merge([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $test->mhrws->id,
        'target_office' => 'PENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 3',
        'date_accomplished' => '2026-08-03',
        'created_by' => $test->user->id,
        'updated_by' => $test->user->id,
    ], $overrides));
}

test('MHRWS reports bypass CENRO and enter PENRO receipt directly', function () {
    $report = mhrwsConservationReport($this);
    $record = app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id);

    expect($record['submission_origin'])->toBe('PENRO')
        ->and($record['cenro_release_applicable'])->toBeFalse()
        ->and($record['stage'])->toBe(SubmissionTrackingService::PENRO_RECEIPT)
        ->and(app(SubmissionTrackingService::class)->queues()[SubmissionTrackingService::CENRO_RELEASE]->pluck('source_id'))->not->toContain($report->id)
        ->and(app(SubmissionTrackingService::class)->queues()[SubmissionTrackingService::PENRO_RECEIPT]->pluck('source_id'))->toContain($report->id);
});

test('normal protected-area reports still enter CENRO release first', function () {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb',
        'protected_area_id' => $this->normalArea->id,
        'target_office' => 'CENRO Baganga',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'date_accomplished' => '2026-08-03',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    expect(app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id)['stage'])
        ->toBe(SubmissionTrackingService::CENRO_RELEASE);
});

test('MHRWS aliases and seeded display names resolve to the same PENRO origin', function () {
    $variant = ProtectedArea::create([
        'name' => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)',
        'short_name' => 'MHRWS',
        'category' => 'Wildlife Sanctuary',
        'municipality' => 'San Isidro',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);
    $report = mhrwsConservationReport($this, ['protected_area_id' => $variant->id]);

    expect(app(SubmissionTrackingService::class)->records()->firstWhere('source_id', $report->id)['stage'])
        ->toBe(SubmissionTrackingService::PENRO_RECEIPT);
});

test('MHRWS receipt advances to endorsement and then history without requiring a CENRO date', function () {
    $report = mhrwsConservationReport($this);
    $tracking = app(SubmissionTrackingService::class);

    $tracking->transition('conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT, '2026-08-06', $this->user->id);
    $report->refresh();
    expect($report->date_report_released_cenro)->toBeNull()
        ->and($tracking->queues()[SubmissionTrackingService::PENRO_RECEIPT]->pluck('source_id'))->not->toContain($report->id)
        ->and($tracking->queues()[SubmissionTrackingService::REGIONAL_ENDORSEMENT]->pluck('source_id'))->toContain($report->id)
        ->and($tracking->queues()['history']->where('source', 'conservation')->pluck('source_id'))->not->toContain($report->id);

    $tracking->transition('conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT, '2026-08-07', $this->user->id);
    $history = $tracking->queues()['history']->where('source', 'conservation');
    expect($history->pluck('source_id'))->toContain($report->id)
        ->and($history->firstWhere('source_id', $report->id)['completed_at'])->toBe('2026-08-07');
});

test('the MHRWS routing rule applies to another protected-area report source', function () {
    $report = BmsReportSubmission::create([
        'protected_area_id' => $this->mhrws->id,
        'target_office' => 'PENRO Mati',
        'activity_name' => 'BMS Report',
        'document_type' => 'Final Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);

    $record = app(SubmissionTrackingService::class)->records()->firstWhere(fn (array $item): bool => $item['source'] === 'bms' && $item['source_id'] === $report->id);
    expect($record['stage'])->toBe(SubmissionTrackingService::PENRO_RECEIPT)
        ->and($record['cenro_release_applicable'])->toBeFalse();
});

test('MHRWS overdue alerts remain active until PENRO receipt and then close', function () {
    $report = mhrwsConservationReport($this, ['date_accomplished' => '2026-07-01']);
    $alerts = app(OverdueReportService::class);
    $today = CarbonImmutable::parse('2026-08-28', 'Asia/Manila');

    expect($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->not->toBeNull();

    $report->update(['date_report_released_cenro' => '2026-07-10']);
    expect($alerts->overdueReports($today)->firstWhere('sourceId', $report->id))->not->toBeNull();

    $report->update(['date_received_penro' => '2026-07-15']);
    expect($alerts->overdueReports($today)
        ->first(fn ($alert): bool => $alert->sourceId === $report->id && $alert->complianceIssue === 'Report Not Yet Submitted'))
        ->toBeNull();
});
