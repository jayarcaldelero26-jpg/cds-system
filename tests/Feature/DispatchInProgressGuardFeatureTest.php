<?php

use App\Models\BmsReportSubmission;
use App\Models\DocumentArchive;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DispatchInProgressGuard;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

test('a busy initial dispatch returns a clear redirect error and permits a safe retry after release', function (): void {
    config(['services.google_drive_archive.enabled' => true, 'services.document_archive.driver' => 'fake']);
    $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    Storage::fake('local');

    $actor = function (string $category, string $office): User {
        $user = User::factory()->create([
            'section' => $category,
            'office_designated' => $office,
            'unit_assignment' => OrganizationalAccessService::CONSERVATION,
            'is_active' => true,
            'is_approved' => true,
        ]);
        foreach (['bms.update', 'submission-tracking.view'] as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        return $user;
    };

    $owner = $actor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
    $chief = $actor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati');
    $cenroRecords = $actor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati');
    $penroRecords = $actor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental');
    $area = ProtectedArea::query()->create([
        'name' => 'Dispatch guard HTTP fixture', 'short_name' => 'DGHF',
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->getKey(),
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
    ]);
    $report = BmsReportSubmission::query()->create([
        'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Mati',
        'activity_name' => 'Synthetic dispatch guard HTTP fixture', 'document_type' => 'Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
        'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
    ]);
    $path = 'bms-report-movs/dispatch-guard-http-'.$report->getKey().'.pdf';
    $report->forceFill(['mov_file_path' => $path, 'mov_file_name' => 'Synthetic dispatch fixture.pdf'])->save();
    Storage::disk('local')->put($path, "%PDF-1.4\nSynthetic isolated dispatch HTTP fixture");

    $routing = app(DocumentRoutingTransitionService::class);
    foreach ([
        [$owner, 'forward_to_cenro_chief'],
        [$chief, 'receive_at_cenro_chief'],
        [$chief, 'forward_to_cenro_records'],
        [$cenroRecords, 'receive_at_cenro_records'],
        [$cenroRecords, 'forward_to_penro_records'],
        [$penroRecords, 'receive_at_penro_records'],
    ] as [$user, $action]) {
        $routing->transition($report->fresh(), 'bms', $action, (int) $user->getKey());
    }

    $snapshot = SubmissionRoutingSnapshot::query()->where('source_key', 'bms')->where('source_id', $report->getKey())->firstOrFail();
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $lock = app(DispatchInProgressGuard::class)->acquire('bms', $report->getKey());

    $this->actingAs($penroRecords)
        ->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->getKey(), 'stage' => 'forward_to_office_penro']), [
            'stage' => 'forward_to_office_penro',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['stage' => 'Dispatch already in progress. Wait for the current archive checkpoint to finish, then retry if needed.']);

    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->count())->toBe(6)
        ->and(DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->exists())->toBeFalse()
        ->and($gateway->uploadAttempts())->toBe(0);

    $lock->release();
    $this->actingAs($penroRecords)
        ->post(route('submission-tracking.transition', ['source' => 'bms', 'record' => $report->getKey(), 'stage' => 'forward_to_office_penro']), [
            'stage' => 'forward_to_office_penro',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $dispatch = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->latest('id')->firstOrFail();
    $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->firstOrFail();
    expect(DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->count())->toBe(7)
        ->and(data_get($dispatch->metadata, 'route_setting_version_id'))->toBe($snapshot->setting_version_id)
        ->and(data_get($dispatch->metadata, 'route_snapshot_id'))->toBe($snapshot->getKey())
        ->and($archive->archive_status)->toBe('ARCHIVED')
        ->and($archive->remote_availability)->toBe('verified')
        ->and($gateway->uploadAttempts())->toBe(1);
});
