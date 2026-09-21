<?php

use App\Models\Aws;
use App\Models\AwsObservation;
use App\Models\BamsFlora;
use App\Models\BamsReportSubmission;
use App\Models\BmsRecord;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\EngpReportSubmission;
use App\Models\ImeaAssessment;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\PambMovReviewEvent;
use App\Models\PambRoutingEvent;
use App\Models\ProtectedArea;
use App\Models\ReportTrackingReference;
use App\Models\SubmissionRoutingAttachment;
use App\Models\SubmissionRoutingCorrection;
use App\Models\SubmissionRoutingOverride;
use App\Models\User;
use App\Services\Reports\ReportTrackingNumberService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function p1dArea(User $user): ProtectedArea
{
    return ProtectedArea::create(['name' => 'P1-D Integration PA', 'short_name' => 'P1D', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
}

function p1dUser(): User
{
    return User::factory()->create(['is_active' => true, 'is_approved' => true, 'office_designated' => 'CENRO Mati', 'section' => 'CENRO_CDS_FOCAL']);
}

function p1dReports(ProtectedArea $area, User $user): array
{
    $common = ['protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'date_accomplished' => '2026-08-28', 'created_by' => $user->id, 'updated_by' => $user->id];
    $conservation = ConservationReportSubmission::create([...$common, 'workflow_key' => 'homestay', 'activity_name' => 'Homestay', 'document_type' => 'Final Report', 'reporting_period' => '2026']);
    $pambRegular = ConservationReportSubmission::create([...$common, 'workflow_key' => 'regular_pamb', 'activity_name' => 'Regular PAMB', 'document_type' => 'Resolution', 'date_conducted' => '2026-08-20']);
    $pambSpecial = ConservationReportSubmission::create([...$common, 'workflow_key' => 'special_pamb', 'activity_name' => 'Special PAMB', 'document_type' => 'Resolution', 'date_conducted' => '2026-08-21']);
    $bms = BmsReportSubmission::create([...$common, 'semester' => '1st Semester', 'activity_name' => 'BMS report', 'document_type' => 'Final Report']);
    $bams = BamsReportSubmission::create([...$common, 'semester' => '1st Semester', 'activity_name' => 'BAMS report', 'document_type' => 'Final Report']);
    $imea = ImeaReportSubmission::create([...$common, 'semester' => '1st Semester', 'activity_name' => 'IMEA report', 'document_type' => 'Final Report']);
    $maintenance = ImeaFacilityMaintenanceReport::create([...$common, 'quarter' => 'Q3', 'activity_name' => 'IMEA Facility Maintenance', 'document_type' => 'Final Report']);
    $aws = Aws::create(['station_name' => 'P1-D AWS', 'location' => 'P1-D Integration PA', ...$common, 'activity_name' => 'AWS report', 'document_type' => 'Final Report']);
    $ipaf = IpafManagementReport::create([...$common, 'activity_name' => 'IPAF Management', 'document_type' => 'Final Report']);
    $revenue = IpafRevenueCollection::create([...array_diff_key($common, ['date_accomplished' => true]), 'document_type' => 'Revenue Report', 'reporting_month' => 8, 'reporting_year' => 2026, 'total_collected' => '1000.00', 'deadline_submission' => '2026-09-30']);
    $engp = EngpReportSubmission::create(['workflow_key' => 'site_visit', 'office' => 'CENRO Mati', 'activity_name' => 'ENGP report', 'document_type' => 'Monthly Report', 'reporting_year' => 2026, 'period_key' => 'P1D', 'period_label' => 'P1-D', 'deadline_submission' => '2026-09-30', 'created_by' => $user->id, 'updated_by' => $user->id]);

    return compact('conservation', 'pambRegular', 'pambSpecial', 'bms', 'bams', 'imea', 'maintenance', 'aws', 'ipaf', 'revenue', 'engp');
}

function p1dSourceMap(): array
{
    return [
        'conservation' => ConservationReportSubmission::class,
        'bms' => BmsReportSubmission::class,
        'bams' => BamsReportSubmission::class,
        'imea' => ImeaReportSubmission::class,
        'imea-maintenance' => ImeaFacilityMaintenanceReport::class,
        'aws' => Aws::class,
        'ipaf-management' => IpafManagementReport::class,
        'revenue' => IpafRevenueCollection::class,
        'engp' => EngpReportSubmission::class,
    ];
}

test('populated trackable families have unique non-orphan references and raw rows stay untracked', function (): void {
    $user = p1dUser(); $area = p1dArea($user); $records = p1dReports($area, $user);
    BmsRecord::create(['protected_area_id' => $area->id, 'monitoring_date' => '2026-08-28', 'taxonomic_group' => 'Birds', 'species_scientific_name' => 'P1d test']);
    DB::table('bams_flora')->insert(['protected_area_id' => $area->id, 'quadrat_no' => 1, 'tree_no' => 1, 'species' => 'P1-D test', 'scientific_name' => 'P1d.test', 'created_at' => now(), 'updated_at' => now()]);
    ImeaAssessment::create(['protected_area_id' => $area->id, 'assessment_year' => 2026, 'assessment_period' => 'Annual', 'pamo_name' => 'P1-D', 'created_by' => $user->id, 'updated_by' => $user->id]);
    AwsObservation::create(['protected_area_id' => $area->id, 'station_name' => 'Raw AWS', 'location' => 'P1-D', 'start_date' => '2026-08-28', 'end_date' => '2026-08-28']);

    $service = app(ReportTrackingNumberService::class);
    $items = collect(p1dSourceMap())->map(fn (string $model, string $key): array => ['record' => collect($records)->first(fn ($record) => $record instanceof $model), 'key' => $key])->values();
    $references = $service->ensureFor($items);

    expect($references)->toHaveCount(count(p1dSourceMap()))
        ->and(ReportTrackingReference::query()->count())->toBe(count(p1dSourceMap()))
        ->and(ReportTrackingReference::query()->select('source_type', 'source_id')->groupBy('source_type', 'source_id')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and(ReportTrackingReference::query()->select('tracking_number')->groupBy('tracking_number')->havingRaw('COUNT(*) > 1')->count())->toBe(0)
        ->and(ReportTrackingReference::query()->whereIn('source_type', ['aws-observation', 'bms-record', 'bams-flora', 'imea-assessment'])->count())->toBe(0);

    foreach (ReportTrackingReference::query()->get() as $reference) {
        $model = p1dSourceMap()[$reference->source_type];
        expect($model::query()->find($reference->source_id))->not->toBeNull();
    }
});

test('generic and direct-PENRO histories remain chronological with one authoritative terminal state', function (): void {
    $user = p1dUser(); $area = p1dArea($user); $records = p1dReports($area, $user);
    $registry = app(DocumentRoutingProfileRegistry::class);
    $actions = $registry->actionProfile('bms', false)['actions'];
    $path = ['forward_to_cenro_chief', 'receive_at_cenro_chief', 'forward_to_cenro_records', 'receive_at_cenro_records', 'forward_to_penro_records', 'receive_at_penro_records', 'receive_at_office_penro', 'assign_to_tsd_chief', 'receive_at_tsd_chief', 'forward_to_cds_focal', 'receive_at_cds_focal', 'forward_to_cds_chief', 'receive_at_cds_chief', 'recommend_to_office_penro', 'receive_at_office_penro_final', 'approve_for_regional_release', 'receive_at_penro_records_final', 'release_to_regional'];
    $at = CarbonImmutable::parse('2026-08-28 08:00:00', 'Asia/Manila');
    foreach ($path as $key) {
        $action = collect($actions)->firstWhere('key', $key);
        DocumentRoutingEvent::create(['source_type' => 'bms', 'source_id' => $records['bms']->id, 'workflow_key' => 'bms', 'event_key' => $action['event_key'], 'from_stage' => $action['from'], 'to_stage' => $action['to'], 'from_office' => $action['from_office'], 'to_office' => $action['to_office'], 'occurred_at' => $at, 'recorded_by' => $user->id, 'metadata' => ['action_key' => $key]]);
        $at = $at->addMinute();
    }
    $state = app(DocumentRoutingTransitionService::class)->state($records['bms'], 'bms');
    expect($state['stage'])->toBe(DocumentRoutingProfileRegistry::RELEASED_REGIONAL)
        ->and($state['events']->pluck('occurred_at')->sort()->values()->all())->toEqual($state['events']->pluck('occurred_at')->values()->all())
        ->and(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $records['bms']->id)->count())->toBe(count($path));

    $direct = $registry->actionProfile('bms', true)['actions'];
    foreach (array_slice($direct, 0, 2) as $index => $action) {
        DocumentRoutingEvent::create(['source_type' => 'bams', 'source_id' => $records['bams']->id, 'workflow_key' => 'bams', 'event_key' => $action['event_key'], 'from_stage' => $action['from'], 'to_stage' => $action['to'], 'from_office' => $action['from_office'], 'to_office' => $action['to_office'], 'occurred_at' => $at->addMinutes($index), 'recorded_by' => $user->id, 'metadata' => ['action_key' => $action['key']]]);
    }
    expect(DocumentRoutingEvent::query()->where('source_type', 'bams')->where('source_id', $records['bams']->id)->whereIn('from_stage', [DocumentRoutingProfileRegistry::PREPARATION, DocumentRoutingProfileRegistry::CENRO_CHIEF, DocumentRoutingProfileRegistry::CENRO_RECORDS])->count())->toBe(0);
});

test('PAMB MOV, correction, override, and attachment references point to valid history', function (): void {
    Storage::fake('local'); $user = p1dUser(); $area = p1dArea($user); $records = p1dReports($area, $user);
    $pambEvent = PambRoutingEvent::create(['conservation_report_submission_id' => $records['pambRegular']->id, 'workflow_key' => 'regular_pamb', 'stage_key' => 'cenro_review', 'occurred_at' => now(), 'recorded_by' => $user->id]);
    PambMovReviewEvent::create(['conservation_report_submission_id' => $records['pambRegular']->id, 'event_key' => 'mov_approved', 'remarks' => 'Valid MOV', 'recorded_by' => $user->id]);
    $docEvent = DocumentRoutingEvent::create(['source_type' => 'imea', 'source_id' => $records['imea']->id, 'workflow_key' => 'imea', 'event_key' => 'forwarded', 'from_stage' => 'cenro_preparation', 'to_stage' => 'transit_to_cenro_chief', 'from_office' => 'CENRO CDS Focal Person', 'to_office' => 'CENRO CDS Chief', 'occurred_at' => now(), 'recorded_by' => $user->id]);
    $path = 'p1d/attachment.pdf'; Storage::disk('local')->put($path, 'P1-D');
    SubmissionRoutingAttachment::create(['source' => 'imea', 'source_id' => $records['imea']->id, 'document_routing_event_id' => $docEvent->id, 'stage_key' => 'cenro_preparation', 'action_key' => 'forward', 'original_name' => 'attachment.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => 4, 'uploaded_by' => $user->id]);
    SubmissionRoutingCorrection::create(['source' => 'imea', 'source_id' => $records['imea']->id, 'field' => 'date_accomplished', 'original_value' => '2026-08-28 00:00:00', 'corrected_value' => '2026-08-29 00:00:00', 'reason' => 'Fixture correction', 'corrected_by' => $user->id, 'corrected_at' => now()]);
    SubmissionRoutingOverride::create(['source' => 'imea', 'source_record_id' => $records['imea']->id, 'engine' => 'generic', 'action_key' => 'forward', 'event_key' => 'forwarded', 'actual_actor_user_id' => $user->id, 'actual_actor_category' => 'admin', 'overridden_accountable_category' => 'CENRO CDS Focal', 'overridden_office' => 'CENRO Mati', 'protected_area_id' => $area->id, 'reason' => 'Fixture override', 'authentication_method' => 'password', 'previous_stage' => 'cenro_preparation', 'resulting_stage' => 'transit_to_cenro_chief']);

    expect(PambRoutingEvent::query()->whereDoesntHave('submission')->count())->toBe(0)
        ->and(PambMovReviewEvent::query()->whereDoesntHave('submission')->count())->toBe(0)
        ->and(DocumentRoutingEvent::query()->where('source_type', 'imea')->where('source_id', $records['imea']->id)->count())->toBe(1)
        ->and(SubmissionRoutingAttachment::query()->whereDoesntHave('documentRoutingEvent')->whereDoesntHave('pambRoutingEvent')->count())->toBe(0)
        ->and(SubmissionRoutingCorrection::query()->where('source', 'imea')->where('source_id', $records['imea']->id)->count())->toBe(1)
        ->and(SubmissionRoutingOverride::query()->where('source', 'imea')->where('source_record_id', $records['imea']->id)->whereNotNull('reason')->count())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

test('soft-deleted sources are absent from live source queries and integrity checks remain empty', function (): void {
    $user = p1dUser(); $area = p1dArea($user); $records = p1dReports($area, $user);
    $records['engp']->delete();
    expect(EngpReportSubmission::query()->find($records['engp']->id))->toBeNull()
        ->and(DB::table('document_routing_events')->whereNotIn('source_type', array_keys(p1dSourceMap()))->count())->toBe(0)
        ->and(DB::table('submission_routing_corrections')->whereNull('source_id')->count())->toBe(0)
        ->and(DB::table('submission_routing_overrides')->whereNull('source_record_id')->count())->toBe(0)
        ->and(DB::table('submission_routing_attachments')->whereNull('source_id')->count())->toBe(0);
});
