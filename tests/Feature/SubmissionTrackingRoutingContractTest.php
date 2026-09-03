<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\EngpReportSubmission;
use App\Models\ProtectedArea;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\Authorization\OrganizationalAccessService;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->user = User::factory()->create(['section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $this->user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $this->user->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));
    $this->user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));
});

test('active non-PAMB sources share the canonical routing profile contract', function (): void {
    $registry = app(DocumentRoutingProfileRegistry::class);

    foreach (['bms', 'bams', 'imea', 'aws', 'imea-maintenance', 'ipaf-management', 'revenue', 'management-plans', 'conservation'] as $source) {
        $profile = $registry->profile($source);

        expect($profile)->toHaveKeys(['key', 'originating_office', 'final_destination', 'route_granularity', 'business_route_confirmation'])
            ->and($profile['route_granularity'])->toBe('detailed')
            ->and($profile['detailed_route_requires_confirmation'])->toBeFalse();
    }

    expect($registry->profile('engp')['route_granularity'])->toBe('release_components');
});

test('generic tracking presents server-derived location and keeps forward separate from receipt', function (): void {
    $area = ProtectedArea::create(['name' => 'Contract PA', 'short_name' => 'CPA', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id')]);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'BMS contract report',
        'document_type' => 'Semestral Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-08-03',
    ]);
    $tracking = app(SubmissionTrackingService::class);
    $initial = $tracking->records()->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    expect($initial['routing']['current_location'])->toBe('CENRO Mati')
        ->and($initial['routing']['current_status'])->toBe('Awaiting Forward to CENRO CDS Chief')
        ->and(collect($initial['routing']['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)['status'])->toBe('pending');

    $tracking->transition('bms', $report->id, 'forward_to_cenro_chief', null, $this->user->id);
    $released = $tracking->records()->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id);
    expect(DocumentRoutingEvent::query()->count())->toBe(1)
        ->and($released['routing']['current_location'])->toBe('In transit to CENRO CDS Chief')
        ->and($released['routing']['in_transit_to'])->toBe('CENRO CDS Chief')
        ->and($released['routing']['current_status'])->toBe('In Transit to CENRO CDS Chief')
        ->and(collect($released['routing']['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)['event_type'])->toBe('forwarded')
        ->and(collect($released['routing']['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF)['recorded_by'])->toBe($this->user->name)
        ->and(collect($released['routing']['timeline'])->firstWhere('key', DocumentRoutingProfileRegistry::CENRO_CHIEF)['occurred_at'])->toBeNull();
});

test('CENRO tracker scope includes routed non-PAMB records for its office', function (): void {
    $area = ProtectedArea::create(['name' => 'Scoped Contract PA', 'short_name' => 'SCPA', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
    ProtectedAreaOfficeAssignment::create(['protected_area_id' => $area->id, 'organizational_office_id' => OrganizationalOffice::where('code', 'cenro_mati')->value('id')]);
    $report = BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'activity_name' => 'Scoped BMS report',
        'document_type' => 'Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);

    $this->actingAs($this->user);
    expect(app(SubmissionTrackingService::class)->records()->firstWhere(fn (array $row): bool => $row['source'] === 'bms' && (int) $row['source_id'] === $report->id))->not->toBeNull();
});

test('ENGP keeps its release-component route while using the shared contract', function (): void {
    $report = EngpReportSubmission::create([
        'workflow_key' => 'ngp_produce', 'office' => 'CENRO Mati', 'section_name' => 'NGP',
        'activity_name' => 'ENGP Produce', 'document_type' => 'Quarterly Report', 'reporting_year' => 2026,
        'period_key' => 'Q1', 'period_label' => 'Quarter 1', 'deadline_submission' => '2026-03-10',
    ]);
    $report->releaseEvents()->create(['period_component' => '2026-01', 'component_label' => 'January', 'date_report_released_cenro' => '2026-01-10']);

    $row = app(SubmissionTrackingService::class)->records()->firstWhere(fn (array $item): bool => $item['source'] === 'engp' && (int) $item['source_id'] === $report->id);

    expect($row['routing']['profile_key'])->toBe('engp_release_components')
        ->and($row['routing']['route_granularity'])->toBe('release_components')
        ->and(collect($row['routing']['timeline'])->firstWhere('key', 'cenro_release:2026-01')['event_type'])->toBe('forwarded')
        ->and(collect($row['routing']['timeline'])->last()['key'])->toBe(SubmissionTrackingService::PENRO_RECEIPT);
});

test('PAMB retains its detailed routing profile through the shared contract adapter', function (): void {
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'activity_name' => 'PAMB contract report',
        'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
    ]);

    $row = app(SubmissionTrackingService::class)->records()->firstWhere(fn (array $item): bool => $item['source'] === 'conservation' && (int) $item['source_id'] === $report->id);

    expect($row['routing']['profile_key'])->toBe('pamb_detailed')
        ->and($row['pamb_routing_applicable'])->toBeTrue()
        ->and($row['routing']['route_granularity'])->toBe('detailed');
});
