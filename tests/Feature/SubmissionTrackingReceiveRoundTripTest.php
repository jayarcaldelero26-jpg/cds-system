<?php

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\User;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

test('CDS Chief Receive and Recommend are separate requests with no archive provider call', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    Storage::fake('local');
    Storage::fake('public');

    $drive = Mockery::mock(GoogleDriveArchiveGateway::class);
    foreach (['findByIdentityAndHash', 'upload', 'verify', 'verifyAvailability', 'replace', 'retrieve'] as $method) {
        $drive->shouldNotReceive($method);
    }
    app()->instance(GoogleDriveArchiveGateway::class, $drive);

    $actor = static function (string $category): User {
        $user = User::factory()->create([
            'section' => $category,
            'office_designated' => 'PENRO Davao Oriental',
            'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        ]);
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('technical-reports.update', 'web'));

        return $user;
    };

    $sender = $actor(OrganizationalAccessService::PENRO_FOCAL);
    $chief = $actor(OrganizationalAccessService::PENRO_CHIEF);
    $office = $actor(OrganizationalAccessService::OFFICE_PENRO);
    $report = ConservationReportSubmission::create([
        'workflow_key' => 'maintenance_monuments',
        'activity_name' => 'Maintenance of Monuments',
        'document_type' => 'Final Report',
        'target_office' => 'CENRO Mati',
        'date_accomplished' => '2026-08-03',
        'created_by' => $sender->id,
        'updated_by' => $sender->id,
    ]);

    // Start at the state shown in the recording; the prior forwarding event is
    // fixture history, while Receive and Recommend below are real HTTP posts.
    $profile = app(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::class)
        ->actionProfile('conservation');
    $forward = collect($profile['actions'])->firstWhere('key', 'forward_to_cds_chief');
    expect($forward)->not->toBeNull();
    DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'workflow_key' => $report->workflow_key,
        'event_key' => $forward['event_key'],
        'from_stage' => $forward['from'],
        'to_stage' => $forward['to'],
        'from_office' => $forward['from_office'],
        'to_office' => $forward['to_office'],
        'occurred_at' => now()->subMinute(),
        'recorded_by' => $sender->id,
        'metadata' => ['action_key' => $forward['key'], 'state_source' => 'routing_events'],
    ]);

    $receiveUrl = route('submission-tracking.transition', [
        'source' => 'conservation',
        'record' => $report->id,
        'stage' => 'receive_at_cds_chief',
    ]);
    $refreshUrl = route('submission-tracking.index', [
        'view' => 'incoming',
        'source' => 'conservation',
        'source_id' => $report->id,
    ]);

    $this->actingAs($chief)->get($refreshUrl)->assertOk()->assertInertia(fn ($page) => $page
        ->component('SubmissionTracking/Index')
        ->where('trackingContext.selected_source', 'conservation')
        ->where('trackingContext.selected_id', $report->id)
        ->where('trackingContext.selected_record.source', 'conservation')
        ->where('trackingContext.selected_record.source_id', $report->id)
        ->where('trackingContext.selected_record.routing.current_stage', DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF)
        ->has('trackingContext.selected_record.routing.actions', 1)
        ->where('trackingContext.selected_record.routing.actions.0.key', 'receive_at_cds_chief'));

    $started = hrtime(true);
    $this->actingAs($chief)->from($refreshUrl)->post($receiveUrl, [
        'stage' => 'receive_at_cds_chief',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $receivePostMs = (hrtime(true) - $started) / 1_000_000;

    $receivedEvent = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->latest('id')->first();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(2)
        ->and($receivedEvent?->metadata['action_key'])->toBe('receive_at_cds_chief');

    $started = hrtime(true);
    $this->actingAs($chief)->from($refreshUrl)->post($receiveUrl, [
        'stage' => 'receive_at_cds_chief',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    $duplicateReceivePostMs = (hrtime(true) - $started) / 1_000_000;
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(2);

    $started = hrtime(true);
    $this->actingAs($chief)->get($refreshUrl)->assertOk()->assertInertia(fn ($page) => $page
        ->component('SubmissionTracking/Index')
        ->where('trackingContext.selected_source', 'conservation')
        ->where('trackingContext.selected_id', $report->id)
        ->where('trackingContext.selected_record.source', 'conservation')
        ->where('trackingContext.selected_record.source_id', $report->id)
        ->where('trackingContext.selected_record.routing.current_stage', DocumentRoutingProfileRegistry::CDS_CHIEF)
        ->has('trackingContext.selected_record.routing.actions', 2)
        ->where('trackingContext.selected_record.routing.actions.0.key', 'return_to_penro_cds_focal')
        ->where('trackingContext.selected_record.routing.actions.1.key', 'recommend_to_office_penro'));
    $refreshGetMs = (hrtime(true) - $started) / 1_000_000;

    $recommendUrl = route('submission-tracking.transition', [
        'source' => 'conservation',
        'record' => $report->id,
        'stage' => 'recommend_to_office_penro',
    ]);
    $started = hrtime(true);
    $this->actingAs($chief)->from($refreshUrl)->post($recommendUrl, [
        'stage' => 'recommend_to_office_penro',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $recommendPostMs = (hrtime(true) - $started) / 1_000_000;

    $recommendedEvent = DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->latest('id')->first();
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(3)
        ->and($recommendedEvent?->metadata['action_key'])->toBe('recommend_to_office_penro');

    $started = hrtime(true);
    $this->actingAs($chief)->from($refreshUrl)->post($recommendUrl, [
        'stage' => 'recommend_to_office_penro',
    ])->assertRedirect()->assertSessionHasErrors('stage');
    $duplicateRecommendPostMs = (hrtime(true) - $started) / 1_000_000;
    expect(DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe(3);

    if (filter_var(getenv('CDS_RECEIVE_REPLAY_TIMINGS') ?: false, FILTER_VALIDATE_BOOL)) {
        fwrite(STDOUT, json_encode([
            'fixture' => 'isolated_sqlite',
            'source' => 'conservation',
            'record_id' => $report->id,
            'receive_action' => 'receive_at_cds_chief',
            'receive_post_ms' => round($receivePostMs, 2),
            'redirect_refresh_get_ms' => round($refreshGetMs, 2),
            'duplicate_receive_post_ms' => round($duplicateReceivePostMs, 2),
            'recommend_action' => 'recommend_to_office_penro',
            'recommend_post_ms' => round($recommendPostMs, 2),
            'duplicate_recommend_post_ms' => round($duplicateRecommendPostMs, 2),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
});
