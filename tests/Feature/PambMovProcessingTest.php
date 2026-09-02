<?php

use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\NonWorkingDay;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pambRoleUser(string $role, string $section, string $office = 'CENRO Mati', array $attributes = []): User
{
    $user = User::factory()->create([...$attributes, 'section' => $section, 'office_designated' => $office]);
    $spatieRole = Role::findOrCreate($role, 'web');
    $permissions = collect([
        'reports.view',
        'technical-reports.view',
        'technical-reports.create',
        'technical-reports.update',
    ])->map(fn (string $permission) => Permission::findOrCreate($permission, 'web'))->all();
    $spatieRole->syncPermissions($permissions);
    $user->assignRole($spatieRole);

    return $user;
}

function pambReport(User $user, array $overrides = []): ConservationReportSubmission
{
    return ConservationReportSubmission::create([...[
        'workflow_key' => 'regular_pamb',
        'target_office' => 'CENRO Mati',
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 1',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ], ...$overrides]);
}

test('a conducted record without an MOV starts at zero percent and an uploaded MOV is thirty-five percent', function (): void {
    $user = User::factory()->create();
    $service = app(PambMovProcessingService::class);
    $report = pambReport($user);

    expect($service->present($report)['percent'])->toBe(0)
        ->and($service->present($report)['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED);

    $report->update(['mov_file_path' => 'conservation-report-movs/example.pdf']);
    expect($service->present($report->fresh())['percent'])->toBe(35)
        ->and($service->present($report->fresh())['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED)
        ->and($service->present($report->fresh())['status_label'])->toBe('MOV Uploaded / Ready for Submission')
        ->and($service->present($report->fresh())['workflow_status'])->toBe('Pending Submission by CENRO')
        ->and($service->present($report->fresh())['queue'])->toBe('for_submission');
});

test('saving a MOV records upload without submitting it for review', function (): void {
    Storage::fake('local');
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL', 'CENRO Mati');

    $this->actingAs($focal)->post(route('conservation-reports.store', ['workflow' => 'regular_pamb']), [
        'target_office' => 'CENRO Mati',
        'protected_area_id' => null,
        'activity_name' => 'Regular PAMB',
        'document_type' => 'Minutes',
        'reporting_period' => 'Quarter 2',
        'date_conducted' => '2026-08-03',
        'date_accomplished' => '2026-08-03',
        'mov' => UploadedFile::fake()->create('quarter-2.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $report = ConservationReportSubmission::query()->latest('id')->firstOrFail();
    $present = app(PambMovProcessingService::class)->present($report);

    expect($present['percent'])->toBe(35)
        ->and($present['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED)
        ->and($present['status_label'])->toBe('MOV Uploaded / Ready for Submission')
        ->and($present['queue'])->toBe('for_submission')
        ->and($report->movReviewEvents()->pluck('event_key')->all())->toBe([PambMovProcessingService::MOV_UPLOADED]);

    $snapshot = app(SubmissionTrackingService::class)->snapshot();
    expect($snapshot['queues']['for_submission']->pluck('source_id')->all())->toContain($report->id)
        ->and($snapshot['queues']['for_review']->pluck('source_id')->all())->not->toContain($report->id);
});

test('submitting for review is idempotent within a review cycle', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/idempotent.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $submittedAt = $report->fresh()->mov_submitted_at;

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $submitted = $report->fresh();

    expect($submitted->mov_processing_status)->toBe(PambMovProcessingService::SUBMITTED_FOR_REVIEW)
        ->and($submitted->mov_submitted_at?->equalTo($submittedAt))->toBeTrue()
        ->and($submitted->movReviewEvents()->where('event_key', PambMovProcessingService::SUBMITTED_FOR_REVIEW)->count())->toBe(1)
        ->and(app(PambMovProcessingService::class)->present($submitted)['workflow_status'])->toBe('Awaiting Review by CENRO CDS Chief');

    $snapshot = app(SubmissionTrackingService::class)->snapshot();
    expect($snapshot['queues']['for_submission']->pluck('source_id')->all())->not->toContain($report->id)
        ->and($snapshot['queues']['for_review']->pluck('source_id')->all())->toContain($report->id);
});

test('the active MOV marker uses a compact concentric pulse ring with reduced-motion fallback', function (): void {
    $component = file_get_contents(resource_path('js/Components/SubmissionTracking/PambMovProgress.jsx'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($component)->toContain('edats-current-stage-marker__halo')
        ->toContain('edats-current-stage-marker__ripple--first')
        ->toContain('edats-current-stage-marker__ripple--second')
        ->toContain('disabled={submitting}')
        ->and($styles)->toContain('@keyframes edats-current-stage-ripple')
        ->toContain('2.2s ease-out infinite')
        ->toContain('animation-delay: 1.1s')
        ->toContain('scale(0.70)')
        ->toContain('scale(1.55)')
        ->toContain('prefers-reduced-motion: reduce');
});

test('Chief review supports ready and correction decisions without changing compliance values', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/review.pdf']);
    $deadline = $report->deadline_submission;
    $timeliness = $report->timeliness;

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Please attach the signed attendance sheet.'])->assertSessionHasNoErrors();

    $corrected = $report->fresh();
    expect($corrected->mov_processing_status)->toBe(PambMovProcessingService::NEEDS_CORRECTION)
        ->and(app(PambMovProcessingService::class)->present($corrected)['percent'])->toBe(35)
        ->and($corrected->deadline_submission)->toBe($deadline)
        ->and($corrected->timeliness)->toBe($timeliness)
        ->and($corrected->movReviewEvents()->count())->toBe(2);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]))->assertSessionHasNoErrors();
    expect($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::SUBMITTED_FOR_REVIEW)->count())->toBe(1)
        ->and($report->fresh()->movReviewEvents()->where('event_key', PambMovProcessingService::RESUBMITTED_FOR_REVIEW)->count())->toBe(1);
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    expect(app(PambMovProcessingService::class)->present($report->fresh())['percent'])->toBe(70);
});

test('Chief review decisions are idempotent within the current review state', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/idempotent-review.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    $reviewedAt = $report->fresh()->mov_reviewed_at;

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE])->assertSessionHasNoErrors();
    $reviewed = $report->fresh();

    expect($reviewed->mov_processing_status)->toBe(PambMovProcessingService::READY_FOR_RELEASE)
        ->and($reviewed->mov_reviewed_at?->equalTo($reviewedAt))->toBeTrue()
        ->and($reviewed->movReviewEvents()->where('event_key', PambMovProcessingService::READY_FOR_RELEASE)->count())->toBe(1);
});

test('CENRO review summary exposes the current verdict and preserves prior correction cycles', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/verdict-summary.pdf']);
    $service = app(PambMovProcessingService::class);
    $service->recordUpload($report, $focal);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    expect($service->present($report->fresh())['cenro_review']['verdict'])->toBe('Awaiting CENRO CDS Chief Review');

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Attach the signed resolution.']);
    $needsCorrection = $service->present($report->fresh())['cenro_review'];
    expect($needsCorrection['verdict'])->toBe('Needs Correction')
        ->and($needsCorrection['reviewed_by'])->toBe($chief->name)
        ->and($needsCorrection['reviewed_user_category'])->toBe('CENRO CDS Chief')
        ->and($needsCorrection['correction_reason'])->toBe('Attach the signed resolution.')
        ->and($needsCorrection['previous_correction_cycles'])->toBe(1);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $awaitingSecondReview = $service->present($report->fresh())['cenro_review'];
    expect($awaitingSecondReview['verdict'])->toBe('Awaiting CENRO CDS Chief Review')
        ->and($awaitingSecondReview['previous_correction']['reason'])->toBe('Attach the signed resolution.');

    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE]);
    $final = $service->present($report->fresh())['cenro_review'];
    expect($final['verdict'])->toBe('Ready for Release')
        ->and($final['reviewed_by'])->toBe($chief->name)
        ->and($final['reviewed_user_category'])->toBe('CENRO CDS Chief')
        ->and($final['previous_correction_cycles'])->toBe(1)
        ->and($final['previous_correction']['reason'])->toBe('Attach the signed resolution.')
        ->and($report->fresh()->movReviewEvents()->oldest('id')->pluck('event_key')->all())->toBe([
            PambMovProcessingService::MOV_UPLOADED,
            PambMovProcessingService::SUBMITTED_FOR_REVIEW,
            PambMovProcessingService::NEEDS_CORRECTION,
            PambMovProcessingService::RESUBMITTED_FOR_REVIEW,
            PambMovProcessingService::READY_FOR_RELEASE,
        ]);
});

test('For Review status presents the Chief as the next action owner', function (): void {
    $component = file_get_contents(resource_path('js/Components/SubmissionTracking/PambMovProgress.jsx'));
    $page = file_get_contents(resource_path('js/Pages/SubmissionTracking/Index.jsx'));

    expect($component)->toContain('Review Status')
        ->toContain('Awaiting Review by CENRO CDS Chief')
        ->toContain('Next Action: CENRO CDS Chief must review this MOV/report.')
        ->toContain('Edit / Correct Submission')
        ->and($page)->toContain("details.mov_processing?.applicable ? 'Workflow Status' : 'Routing Status'")
        ->toContain('details.mov_processing?.applicable ? details.mov_processing.workflow_status : details.submission_status');
});

test('needs correction requires remarks and returns the record to the focal queue', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/correction.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION])->assertSessionHasErrors('remarks');
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::NEEDS_CORRECTION, 'remarks' => 'Please correct the signature page.'])->assertSessionHasNoErrors();
    $this->actingAs($chief)->get(route('submission-tracking.index'))->assertInertia(fn ($page) => $page->where('queues.needs_correction.0.source_id', $report->id));
});

test('records release uses the canonical CENRO release date and reaches one hundred percent', function (): void {
    $focal = pambRoleUser('CENRO CDS Focal Person', 'CENRO_CDS_FOCAL');
    $chief = pambRoleUser('CENRO CDS Chief', 'CENRO_CDS_CHIEF');
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS');
    $report = pambReport($focal, ['mov_file_path' => 'conservation-report-movs/release.pdf']);

    $this->actingAs($focal)->post(route('submission-tracking.mov.submit-review', ['conservation', $report->id]));
    $this->actingAs($chief)->post(route('submission-tracking.mov.review', ['conservation', $report->id]), ['decision' => PambMovProcessingService::READY_FOR_RELEASE]);
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::CENRO_RELEASE]), ['stage' => SubmissionTrackingService::CENRO_RELEASE, 'date' => '2026-08-10'])->assertSessionHasNoErrors();

    $released = $report->fresh();
    expect($released->date_report_released_cenro->toDateString())->toBe('2026-08-10')
        ->and(app(PambMovProcessingService::class)->present($released)['percent'])->toBe(100)
        ->and(app(PambMovProcessingService::class)->present($released)['status_label'])->toBe('Released by CENRO to PENRO');
});

test('CENRO office scope and PAMO protected-area scope are enforced on tracking and attachments', function (): void {
    Storage::fake('local');
    $cenro = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS', 'CENRO Mati');
    $area = ProtectedArea::create(['name' => 'Mati Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $cenro->id, 'updated_by' => $cenro->id]);
    $otherArea = ProtectedArea::create(['name' => 'Baganga Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $cenro->id, 'updated_by' => $cenro->id]);
    $pamo = pambRoleUser('PAMO', 'PAMO', 'PENRO Davao Oriental', ['protected_area_id' => $area->id]);
    $visible = pambReport($cenro, ['protected_area_id' => $area->id, 'mov_file_path' => 'conservation-report-movs/visible.pdf']);
    $hidden = pambReport($cenro, ['target_office' => 'CENRO Baganga', 'protected_area_id' => $otherArea->id, 'mov_file_path' => 'conservation-report-movs/hidden.pdf']);

    $this->actingAs($cenro)->get(route('submission-tracking.index'))->assertInertia(fn ($page) => $page->where('queues.for_release', fn ($queue) => collect($queue)->pluck('source_id')->doesntContain($hidden->id)));
    $this->actingAs($cenro)->get(route('attachments.show', ['conservation-report', $hidden->id, 'mov']))->assertForbidden();
    $this->actingAs($pamo)->get(route('submission-tracking.index'))->assertOk();
    expect($visible->protected_area_id)->toBe($area->id);
});

test('PENRO-managed PAMB uses its legitimate PENRO MOV stages', function (): void {
    $user = User::factory()->create();
    $area = ProtectedArea::create(['name' => 'Mt. Hamiguitan Range Wildlife Sanctuary', 'short_name' => 'MHRWS', 'category' => 'Wildlife Sanctuary', 'municipality' => 'San Isidro', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $user->id, 'updated_by' => $user->id]);
    $report = pambReport($user, ['protected_area_id' => $area->id, 'target_office' => 'PENRO Mati']);

    $present = app(PambMovProcessingService::class)->present($report);
    expect($present['applicable'])->toBeTrue()
        ->and($present['percent'])->toBe(0)
        ->and($present['status_key'])->toBe(PambMovProcessingService::ACTIVITY_CONDUCTED)
        ->and($present['cenro_review']['applicable'])->toBeFalse()
        ->and(collect($present['milestones'])->pluck('key')->all())->not->toContain(PambMovProcessingService::RELEASED_BY_CENRO);
});

test('CENRO users cannot operate PENRO receipt, internal routing, or regional endorsement', function (): void {
    $records = pambRoleUser('CENRO Records Unit', 'CENRO_RECORDS');
    $report = pambReport($records);

    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::PENRO_RECEIPT]), ['stage' => SubmissionTrackingService::PENRO_RECEIPT, 'date' => '2026-08-11'])->assertForbidden();
    $this->actingAs($records)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, 'records_to_penro']), ['stage' => 'records_to_penro', 'occurred_at' => '2026-08-11 09:00'])->assertForbidden();
    $this->actingAs($records)->post(route('submission-tracking.transition', ['conservation', $report->id, SubmissionTrackingService::REGIONAL_ENDORSEMENT]), ['stage' => SubmissionTrackingService::REGIONAL_ENDORSEMENT, 'date' => '2026-08-11'])->assertForbidden();
});

test('turnaround status uses the authoritative PAMB calendar and configured non-working days', function (): void {
    $user = User::factory()->create();
    $report = pambReport($user);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12', 'Asia/Manila'));
    NonWorkingDay::create(['date' => '2026-08-11', 'name' => 'Configured PAMB non-working day', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY, 'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true]);

    try {
        $turnaround = app(PambMovProcessingService::class)->present($report)['turnaround'];
        expect($turnaround['day'])->toBe(5)
            ->and($turnaround['remaining'])->toBe(2)
            ->and($turnaround['deadline'])->toBe('2026-08-17');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
