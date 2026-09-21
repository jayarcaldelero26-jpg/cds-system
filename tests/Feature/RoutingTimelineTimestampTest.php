<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;

function timestampRegressionActor(): User
{
    $user = User::factory()->create([
        'unit_assignment' => 'conservation',
        'section' => OrganizationalAccessService::CENRO_FOCAL,
        'office_designated' => 'CENRO Mati',
    ]);
    $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));

    return $user;
}

function timestampRegressionReport(User $owner, ?string $releaseDate = null): BmsReportSubmission
{
    $area = ProtectedArea::create([
        'name' => 'Timestamp Regression PA',
        'short_name' => 'TRPA',
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'XI',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        'assignment_type' => 'supervising',
    ]);

    return BmsReportSubmission::create([
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Timestamp report',
        'document_type' => 'Report',
        'semester' => '1st Semester',
        'date_accomplished' => '2026-09-10',
        'date_report_released_cenro' => $releaseDate,
    ]);
}

test('real routing events preserve their Asia Manila time while date-only milestones remain date-only', function (): void {
    $actor = timestampRegressionActor();
    $report = timestampRegressionReport($actor);
    $service = app(DocumentRoutingTransitionService::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 14:37:25', 'Asia/Manila'));
    try {
        $service->transition($report, 'bms', 'forward_to_cenro_chief', $actor->id);
    } finally {
        CarbonImmutable::setTestNow();
    }

    $event = DocumentRoutingEvent::query()->firstOrFail();
    expect($event->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-09-12 14:37:25');

    $presentation = $service->presentation($report->fresh(), 'bms', null, $actor);
    $forwarded = collect($presentation['events'])->firstWhere('to_stage', DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF);
    expect($forwarded->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-09-12 14:37:25');

    $legacy = timestampRegressionReport($actor, '2026-09-12');
    $legacyPresentation = $service->presentation($legacy, 'bms', null, $actor);
    $legacyMilestone = collect($legacyPresentation['events'])->isEmpty()
        ? collect(app(DocumentRoutingPresenter::class)->present($legacy, 'bms'))->get('timeline')
        : collect();
    expect(collect($legacyMilestone)->firstWhere('key', 'legacy:cenro_release')['occurred_at'])->toBe('2026-09-12');
});