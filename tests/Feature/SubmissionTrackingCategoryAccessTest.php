<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\EngpReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function categoryTrackingUser(string $category, string $unit = 'conservation', array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'is_active' => true, 'unit_assignment' => $unit, 'section' => $category,
        'office_designated' => str_starts_with($category, 'PENRO_') ? 'PENRO Davao Oriental' : 'CENRO Mati',
        'protected_area_id' => null,
    ], $overrides));
    $user->assignRole(Role::findOrCreate('no_role', 'web'));
    return $user;
}

function categoryTrackingArea(User $owner, string $office = 'CENRO Mati', string $name = 'Scoped PA'): ProtectedArea
{
    $area = ProtectedArea::create([
        'name' => $name, 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $area->id,
        'organizational_office_id' => OrganizationalOffice::where('name', $office)->value('id'),
        'assignment_type' => 'supervising',
    ]);
    return $area;
}

function categoryTrackingReport(ProtectedArea $area, string $office = 'CENRO Mati'): BmsReportSubmission
{
    return BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => $office,
        'activity_name' => 'Scoped workflow regression', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);
}

test('category scope gives matching navigation and route access without general report permissions', function (string $category, string $unit) {
    $user = categoryTrackingUser($category, $unit);
    if ($category === 'PAMO') {
        $area = categoryTrackingArea($user, 'PENRO Davao Oriental', 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)');
        $user->update(['protected_area_id' => $area->id, 'office_designated' => 'PENRO Davao Oriental']);
    }
    $this->actingAs($user)->get('/submission-tracking')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SubmissionTracking/Index')
            ->where('auth.canViewSubmissionTracking', true)->where('auth.canViewReports', false)->where('auth.canManageUsers', false));
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.canViewSubmissionTracking', true));
    foreach (['/admin/users', '/admin/business-calendar', '/admin/recipient-mapping', '/admin/audit-logs'] as $path) $this->get($path)->assertForbidden();
    expect($user->fresh()->getRoleNames()->all())->toBe(['no_role'])->and($user->getAllPermissions())->toHaveCount(0);
})->with([
    ['PENRO_RECORDS', 'conservation'], ['PENRO_CDS_CHIEF', 'conservation'], ['PENRO_CDS_FOCAL', 'conservation'],
    ['CENRO_RECORDS', 'conservation'], ['CENRO_CDS_CHIEF', 'conservation'], ['CENRO_CDS_FOCAL', 'conservation'],
    ['PENRO_RECORDS', 'development'], ['PENRO_CDS_CHIEF', 'development'], ['PENRO_CDS_FOCAL', 'development'],
    ['CENRO_RECORDS', 'development'], ['CENRO_CDS_CHIEF', 'development'], ['CENRO_CDS_FOCAL', 'development'],
]);

test('incomplete or invalid current accounts do not receive tracking capability', function (array $overrides) {
    $user = categoryTrackingUser('PENRO_RECORDS', 'conservation', $overrides);
    expect(app(OrganizationalAccessService::class)->canViewSubmissionTracking($user))->toBeFalse();
    $this->actingAs($user)->get('/submission-tracking')->assertForbidden();
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.canViewSubmissionTracking', false));
})->with([
    [['section' => 'UNKNOWN']], [['section' => 'PAMO', 'protected_area_id' => null]],
    [['section' => 'PAMO', 'unit_assignment' => 'development']],
    [['section' => 'CENRO_RECORDS', 'unit_assignment' => 'development', 'office_designated' => null]],
]);

test('inactive users cannot enter tracking and global admins retain access', function () {
    $inactive = categoryTrackingUser('PENRO_RECORDS', 'conservation', ['is_active' => false]);
    expect(app(OrganizationalAccessService::class)->canViewSubmissionTracking($inactive))->toBeFalse();
    $this->actingAs($inactive)->get('/submission-tracking')->assertRedirect(route('login'));
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $this->actingAs($admin)->get('/submission-tracking')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.canViewSubmissionTracking', true));
    $this->get('/admin/users')->assertOk();
});

test('legacy PAMO accounts cannot enter tracking or mutate routing', function () {
    $pamo = categoryTrackingUser('PAMO');
    $area = categoryTrackingArea($pamo, 'PENRO Davao Oriental', 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)');
    $report = categoryTrackingReport($area, 'PENRO Davao Oriental');

    $this->actingAs($pamo)->get('/submission-tracking')->assertForbidden();
    $this->post('/submission-tracking/bms/'.$report->id.'/forward_to_cenro_chief', [
        'stage' => 'forward_to_cenro_chief',
    ])->assertForbidden();
});

test('role-free routing retains distinct focal chief CENRO records and PENRO records actions', function () {
    $focal = categoryTrackingUser('CENRO_CDS_FOCAL');
    $chief = categoryTrackingUser('CENRO_CDS_CHIEF');
    $records = categoryTrackingUser('CENRO_RECORDS');
    $penro = categoryTrackingUser('PENRO_RECORDS');
    $penroChief = categoryTrackingUser('PENRO_CDS_CHIEF');
    $report = categoryTrackingReport(categoryTrackingArea($focal));
    $post = function (User $actor, string $stage) use ($report) {
        return $this->actingAs($actor)->post('/submission-tracking/bms/'.$report->id.'/'.$stage, ['stage' => $stage]);
    };
    $post($penro, 'forward_to_cenro_chief')->assertForbidden();
    $post($focal, 'forward_to_cenro_chief')->assertRedirect()->assertSessionHasNoErrors();
    $post($records, 'receive_at_cenro_chief')->assertForbidden();
    $post($chief, 'receive_at_cenro_chief')->assertRedirect()->assertSessionHasNoErrors();
    $post($chief, 'forward_to_cenro_records')->assertRedirect()->assertSessionHasNoErrors();
    $post($records, 'receive_at_cenro_records')->assertRedirect()->assertSessionHasNoErrors();
    $post($records, 'forward_to_penro_records')->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->date_received_penro)->toBeNull();
    $post($penroChief, 'receive_at_penro_records')->assertForbidden();
    $post($penro, 'receive_at_penro_records')->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->date_received_penro)->not->toBeNull();
});

test('Development tracking remains office scoped and preserves routing boundaries', function () {
    $user = categoryTrackingUser('CENRO_CDS_FOCAL', 'development', ['office_designated' => 'CENRO Manay']);
    $reports = [];
    foreach (['CENRO Manay', 'CENRO Mati'] as $office) {
        $reports[$office] = EngpReportSubmission::create([
            'workflow_key' => 'ngp_produce', 'office' => $office, 'section_name' => 'NGP', 'activity_name' => 'Office scope regression',
            'document_type' => 'Quarterly Report', 'reporting_year' => 2026, 'period_key' => 'Q1', 'period_label' => 'Quarter 1', 'deadline_submission' => '2026-03-10',
        ]);
        DocumentRoutingEvent::query()->create([
            'source_type' => 'engp', 'source_id' => $reports[$office]->id, 'workflow_key' => 'ngp_produce',
            'event_key' => 'released', 'from_stage' => 'penro_records_final', 'to_stage' => 'released_to_regional',
            'occurred_at' => '2026-03-11 09:00:00', 'recorded_by' => $user->id,
        ]);
    }
    $this->actingAs($user);
    $rows = app(SubmissionTrackingService::class)->records();
    expect($rows)->toHaveCount(1)->and($rows->first()['target_office'])->toBe('CENRO Manay')->and($rows->first()['can_transition'])->toBeTrue();
    $completed = app(SubmissionTrackingService::class)->records(['program' => 'engp', 'status' => 'Completed']);
    expect($completed->pluck('source_id')->all())->toBe([$reports['CENRO Manay']->id]);
    $this->get('/submission-tracking')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('trackingContext.is_cenro_user', true));
    $other = EngpReportSubmission::where('office', 'CENRO Mati')->firstOrFail();
    $this->post('/submission-tracking/engp/'.$other->id.'/forward_to_cenro_chief', ['stage' => 'forward_to_cenro_chief'])->assertForbidden();
    $this->actingAs(categoryTrackingUser('PENRO_RECORDS', 'development'));
    expect(app(SubmissionTrackingService::class)->records())->toHaveCount(2)
        ->and(app(SubmissionTrackingService::class)->records(['program' => 'engp', 'status' => 'Completed'])->pluck('source_id')->all())->toBe([$reports['CENRO Manay']->id, $reports['CENRO Mati']->id]);
});

test('sidebar uses the dedicated tracking capability', function () {
    $source = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
    expect($source)->toContain("permission: 'canViewSubmissionTracking'");
});

test('real CENRO Chief APL shape uses the displayed PA office fallback for tracking access', function () {
    $user = categoryTrackingUser('CENRO_CDS_CHIEF', 'conservation', [
        'office_designated' => 'CENRO Baganga',
    ]);
    $area = ProtectedArea::create([
        'name' => 'Aliwagwag Protected Landscape (APL)', 'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'XI', 'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
    $user->update(['protected_area_id' => $area->id]);
    $report = categoryTrackingReport($area, 'CENRO Baganga');

    expect(ProtectedAreaOfficeAssignment::where('protected_area_id', $area->id)->exists())->toBeFalse()
        ->and(app(OrganizationalAccessService::class)->supervisingOfficeNameForProtectedArea($area->id))->toBe('CENRO Baganga')
        ->and(app(OrganizationalAccessService::class)->hasSubmissionTrackingScope($user->fresh()))->toBeTrue()
        ->and(app(OrganizationalAccessService::class)->canViewSubmissionTracking($user->fresh()))->toBeTrue();

    $this->actingAs($user->fresh())->get('/submission-tracking')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.canViewSubmissionTracking', true));
    $this->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.canViewSubmissionTracking', true));
    expect(app(SubmissionTrackingService::class)->records()->pluck('source_id')->all())->toContain($report->id);
});

test('role-free CENRO Chief reviews CENRO-managed PAMB while PENRO Chief is denied', function () {
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    Storage::disk('local')->put('pamb/review.pdf', "%PDF-1.4\nIsolated role-scope checkpoint fixture");
    $archiveGateway = \Mockery::mock(GoogleDriveArchiveGateway::class);
    $archiveGateway->shouldReceive('findByIdentityAndHash')->once()->andReturnNull();
    $archiveGateway->shouldReceive('upload')->once()->andReturn(['file_id' => 'role-scope-test-archive', 'folder_id' => 'role-scope-test-folder']);
    $archiveGateway->shouldReceive('verify')->once()->with('role-scope-test-archive', \Mockery::type('string'), \Mockery::type('int'))->andReturnTrue();
    $archiveGateway->shouldReceive('verifyAvailability')->once()->with('role-scope-test-archive', \Mockery::type('string'), \Mockery::type('int'))->andReturn('verified');
    app()->instance(GoogleDriveArchiveGateway::class, $archiveGateway);

    $focal = categoryTrackingUser('CENRO_CDS_FOCAL');
    $chief = categoryTrackingUser('CENRO_CDS_CHIEF');
    $penroChief = categoryTrackingUser('PENRO_CDS_CHIEF');
    $records = categoryTrackingUser('PENRO_RECORDS');
    $cenroRecords = categoryTrackingUser('CENRO_RECORDS');
    $area = categoryTrackingArea($chief);
    $report = \App\Models\ConservationReportSubmission::create([
        'workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'PAMB category review regression', 'date_conducted' => '2026-08-03', 'date_accomplished' => '2026-08-03',
        'mov_file_path' => 'pamb/review.pdf', 'mov_processing_status' => 'submitted_for_review',
        'created_by' => $chief->id, 'updated_by' => $chief->id,
    ]);
    $review = route('submission-tracking.mov.review', ['conservation', $report->id]);
    $this->actingAs($penroChief)->post($review, ['decision' => 'ready_for_release'])->assertForbidden();
    $this->actingAs($chief)->post($review, ['decision' => 'ready_for_release'])->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->mov_processing_status)->toBe('ready_for_release');

    $transition = fn (User $actor, string $action) => $this->actingAs($actor)->post(
        route('submission-tracking.transition', ['conservation', $report->id, $action]),
        ['stage' => $action],
    );
    $transition($focal, 'forward_to_cenro_chief')->assertRedirect()->assertSessionHasNoErrors();
    $transition($chief, 'receive_at_cenro_chief')->assertRedirect()->assertSessionHasNoErrors();
    $transition($chief, 'forward_to_cenro_records')->assertRedirect()->assertSessionHasNoErrors();
    $transition($cenroRecords, 'receive_at_cenro_records')->assertRedirect()->assertSessionHasNoErrors();

    $release = route('submission-tracking.transition', ['conservation', $report->id, 'cenro_release']);
    $eventsBeforeDeniedRelease = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $this->actingAs($chief)->post($release, ['stage' => 'cenro_release'])->assertForbidden();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($eventsBeforeDeniedRelease);
    $transition($cenroRecords, 'cenro_release')->assertRedirect()->assertSessionHasNoErrors();

    $eventsBeforeDeniedReceipt = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $this->actingAs($penroChief)->post(route('submission-tracking.transition', ['conservation', $report->id, 'receive_at_penro_records']), ['stage' => 'receive_at_penro_records'])->assertForbidden();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($eventsBeforeDeniedReceipt);
    $transition($records, 'receive_at_penro_records')
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($report->fresh()->date_received_penro)->not->toBeNull();
    $forward = PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO;
    $this->actingAs($records)->post(route('submission-tracking.internal-routing', ['conservation', $report->id, $forward]), [
        'stage' => $forward,
    ])->assertRedirect()->assertSessionHasNoErrors();
});

test('legacy role-owned operational categories do not bypass the category assignment model', function (): void {
    $user = User::factory()->create([
        'is_active' => true, 'unit_assignment' => null, 'section' => 'CDS',
        'office_designated' => 'CENRO Mati', 'protected_area_id' => null,
    ]);
    $user->assignRole(Role::findOrCreate('CENRO CDS Focal Person', 'web'));

    expect(app(OrganizationalAccessService::class)->canViewSubmissionTracking($user))->toBeFalse();
    $this->actingAs($user)->get('/submission-tracking')->assertForbidden();
});

test('same tracking service reevaluates CENRO scope when the authenticated actor changes', function (): void {
    $mati = categoryTrackingUser('CENRO_CDS_FOCAL', 'conservation', ['office_designated' => 'CENRO Mati']);
    $baganga = categoryTrackingUser('CENRO_CDS_FOCAL', 'conservation', ['office_designated' => 'CENRO Baganga']);
    $matiArea = categoryTrackingArea($mati, 'CENRO Mati', 'Mati lifecycle scope PA');
    $bagangaArea = categoryTrackingArea($baganga, 'CENRO Baganga', 'Baganga lifecycle scope PA');
    $matiReport = categoryTrackingReport($matiArea, 'CENRO Mati');
    $bagangaReport = categoryTrackingReport($bagangaArea, 'CENRO Baganga');
    $tracking = app(SubmissionTrackingService::class);

    $this->actingAs($mati);
    $matiRows = $tracking->records(['program' => 'conservation'], null, false);
    $matiPage = $this->get('/submission-tracking')->assertOk()->inertiaProps();
    $this->actingAs($baganga);
    $bagangaRows = $tracking->records(['program' => 'conservation'], null, false);
    $bagangaPage = $this->get('/submission-tracking')->assertOk()->inertiaProps();
    $workspaceIds = fn (array $props) => collect($props['workspaceQueues'])->flatten(1)->pluck('source_id')->map(fn ($id): int => (int) $id);

    expect($matiRows->pluck('source_id')->all())->toContain($matiReport->id)->not->toContain($bagangaReport->id)
        ->and($bagangaRows->pluck('source_id')->all())->toContain($bagangaReport->id)->not->toContain($matiReport->id)
        ->and($matiPage)->not->toHaveKey('queues')
        ->and($workspaceIds($matiPage))->toContain($matiReport->id)->not->toContain($bagangaReport->id)
        ->and($bagangaPage)->not->toHaveKey('queues')
        ->and($workspaceIds($bagangaPage))->toContain($bagangaReport->id)->not->toContain($matiReport->id);
});
