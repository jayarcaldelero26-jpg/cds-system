<?php

use App\Services\Archive\ArchiveCheckpointPolicy;
use App\Services\Archive\FinalReturnArchiveAvailability;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

test('initial archive checkpoints remain mandatory until final return archive schema and flag are ready', function (): void {
    config([
        'services.document_archive.final_return_archive_enabled' => false,
        'services.google_drive_archive.enabled' => false,
    ]);
    $policy = app(ArchiveCheckpointPolicy::class);

    expect(app(FinalReturnArchiveAvailability::class)->schemaReady())->toBeTrue()
        ->and($policy->finalReturnLifecycleEnabled())->toBeFalse()
        ->and($policy->requiresInitialRemoteArchive())->toBeTrue()
        ->and($policy->isCheckpoint('forward_to_office_penro', 'penro_records', 'transit_to_office_of_penro'))->toBeTrue()
        ->and($policy->isCheckpoint('dispatch_penro_records_to_tsd', 'penro_records', 'transit_to_tsd_chief'))->toBeTrue()
        ->and($policy->isCheckpoint('dispatch_penro_records_to_cds_focal', 'penro_records', 'transit_to_cds_focal'))->toBeTrue()
        ->and(fn () => $policy->assertTransitionAllowed('forward_to_office_penro', 'penro_records', 'transit_to_office_of_penro'))
        ->toThrow(ValidationException::class, 'archive checkpoint is disabled');
});

test('active final return lifecycle retargets only the three initial remote prerequisites', function (): void {
    config([
        'services.document_archive.final_return_archive_enabled' => true,
        'services.google_drive_archive.enabled' => false,
    ]);
    $policy = app(ArchiveCheckpointPolicy::class);

    expect($policy->finalReturnLifecycleEnabled())->toBeTrue()
        ->and($policy->requiresInitialRemoteArchive())->toBeFalse()
        ->and($policy->isCheckpoint('forward_to_office_penro', 'penro_records', 'transit_to_office_of_penro'))->toBeFalse()
        ->and($policy->isCheckpoint('dispatch_penro_records_to_tsd', 'penro_records', 'transit_to_tsd_chief'))->toBeFalse()
        ->and($policy->isCheckpoint('dispatch_penro_records_to_cds_focal', 'penro_records', 'transit_to_cds_focal'))->toBeFalse()
        ->and($policy->isCheckpoint('forward_to_office_penro', 'office_of_penro', 'transit_to_tsd_chief'))->toBeFalse();

    $policy->assertTransitionAllowed('forward_to_office_penro', 'penro_records', 'transit_to_office_of_penro');
    $policy->assertTransitionAllowed('dispatch_penro_records_to_tsd', 'penro_records', 'transit_to_tsd_chief');
    $policy->assertTransitionAllowed('dispatch_penro_records_to_cds_focal', 'penro_records', 'transit_to_cds_focal');
});

test('enabled flag cannot retire initial checkpoints when archive schema is unavailable', function (): void {
    config([
        'services.document_archive.final_return_archive_enabled' => true,
        'services.google_drive_archive.enabled' => false,
    ]);
    Schema::shouldReceive('hasTable')->with('final_document_receipts')->atLeast()->once()->andReturn(false);
    $policy = app(ArchiveCheckpointPolicy::class);

    expect($policy->finalReturnLifecycleEnabled())->toBeFalse()
        ->and($policy->requiresInitialRemoteArchive())->toBeTrue()
        ->and($policy->isCheckpoint('forward_to_office_penro', 'penro_records', 'transit_to_office_of_penro'))->toBeTrue()
        ->and(fn () => $policy->assertTransitionAllowed('forward_to_office_penro', 'penro_records', 'transit_to_office_of_penro'))
        ->toThrow(ValidationException::class, 'archive checkpoint is disabled');
});
