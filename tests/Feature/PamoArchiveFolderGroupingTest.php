<?php

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Archive\PamoArchiveFolderGroupingResolver;
use App\Services\Archive\FakeDocumentArchiveGateway;
use App\Services\Archive\FinalDocumentArchiver;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use App\Services\Attachments\StagedFinalDocumentAdapter;
use Database\Seeders\ModuleDefinitionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function pamoArchiveGroupingFixture(string $shortName, string $profile = 'pamo_direct', string $source = 'conservation'): array
{
    $user = User::factory()->create();
    $area = ProtectedArea::query()->create([
        'name' => $shortName.' canonical protected area',
        'short_name' => $shortName,
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $user->getKey(),
        'updated_by' => $user->getKey(),
    ]);
    $pamo = OrganizationalOffice::query()->create([
        'code' => 'pamo_archive_'.$shortName,
        'name' => 'PAMO '.$shortName,
        'office_type' => 'pamo',
        'is_active' => true,
    ]);
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $area->getKey(),
        'organizational_office_id' => $pamo->getKey(),
        'assignment_type' => 'pamo',
        'assigned_by' => $user->getKey(),
    ]);

    $cenro = OrganizationalOffice::query()->where('code', 'cenro_mati')->firstOrFail();
    $record = $source === 'conservation'
        ? ConservationReportSubmission::query()->create([
            'protected_area_id' => $area->getKey(),
            'workflow_key' => 'restoration_plan_5_year',
            'target_office' => 'PENRO Davao Oriental',
            'activity_name' => $shortName.' archive folder fixture',
            'date_conducted' => '2026-10-01',
        ])
        : BmsReportSubmission::query()->create([
            'protected_area_id' => $area->getKey(),
            'target_office' => 'PENRO Davao Oriental',
            'activity_name' => $shortName.' native archive folder fixture',
            'document_type' => 'Report',
            'semester' => '1st Semester',
        ]);

    $versionId = (int) DB::table('routing_position_setting_versions')->value('id');
    $now = now();
    DB::table('submission_routing_snapshots')->insert([
        'source_key' => $source,
        'source_id' => $record->getKey(),
        'setting_version_id' => $versionId,
        'profile' => $profile,
        'graph_version' => RoutingPositionSnapshotService::PAMO_GRAPH_VERSION,
        'captured_by' => $user->getKey(),
        'captured_at' => $now,
        'origin_kind' => 'pamo_pamb',
        'protected_area_id' => $area->getKey(),
        'pamo_office_id' => $pamo->getKey(),
        'pamo_office_name' => $pamo->name,
        'responsible_cenro_office_id' => $profile === 'pamo_regular' ? $cenro->getKey() : null,
        'responsible_cenro_name' => $profile === 'pamo_regular' ? $cenro->name : null,
        'originator_user_id' => $user->getKey(),
        'origin_started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if ($source === 'bms') {
        DB::table('pamo_pamb_drafts')->insert([
            'draft_uuid' => (string) Illuminate\Support\Str::uuid(),
            'protected_area_id' => $area->getKey(),
            'pamo_office_id' => $pamo->getKey(),
            'pamo_office_name' => $pamo->name,
            'originator_user_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
            'workflow_key' => 'bms',
            'started_at' => $now,
            'status' => 'forwarded',
            'bms_report_submission_id' => $record->getKey(),
            'forwarded_at' => $now,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return ['area' => $area, 'record' => $record, 'pamo' => $pamo, 'user' => $user, 'cenro' => $cenro];
}

test('final PA grouping is canonical and independent across direct and regular PAMO origins in A to B to A order', function (): void {
    $a = pamoArchiveGroupingFixture('MHRWS', 'pamo_direct');
    $b = pamoArchiveGroupingFixture('BPL', 'pamo_regular');
    $resolver = app(PamoArchiveFolderGroupingResolver::class);

    expect($resolver->forFinalReturn('conservation', $a['record'], \App\Models\FinalDocumentArchiveManifest::PHASE))->toBe('MHRWS')
        ->and($resolver->forFinalReturn('conservation', $b['record'], \App\Models\FinalDocumentArchiveManifest::PHASE))->toBe('BPL')
        ->and($resolver->forFinalReturn('conservation', $a['record'], \App\Models\FinalDocumentArchiveManifest::PHASE))->toBe('MHRWS')
        ->and($resolver->forFinalReturn('conservation', $a['record'], 'dispatch_checkpoint'))->toBeNull();
});

test('registered native PAMO source uses the linked draft and captured PA identity', function (): void {
    $this->seed(ModuleDefinitionSeeder::class);
    $fixture = pamoArchiveGroupingFixture('MHRWS', 'pamo_direct', 'bms');
    $penro = OrganizationalOffice::query()->where('office_type', 'penro')->where('is_active', true)->firstOrFail();
    ProtectedAreaOfficeAssignment::query()->create([
        'protected_area_id' => $fixture['area']->getKey(),
        'organizational_office_id' => $penro->getKey(),
        'assignment_type' => 'supervising',
    ]);
    $reader = User::factory()->create([
        'section' => \App\Services\Authorization\OrganizationalAccessService::PAMO,
        'unit_assignment' => null,
        'protected_area_id' => $fixture['area']->getKey(),
        'office_designated' => $fixture['pamo']->name,
        'is_active' => true,
        'is_approved' => true,
    ]);
    $reader->assignRole(\Spatie\Permission\Models\Role::findOrCreate('no_role', 'web'));

    expect(app(\App\Services\SubmissionTracking\DocumentRoutingAccessService::class)
        ->canView($reader, $fixture['record'], 'bms'))->toBeTrue()
        ->and(app(PamoArchiveFolderGroupingResolver::class)
            ->forFinalReturn('bms', $fixture['record'], \App\Models\FinalDocumentArchiveManifest::PHASE))
        ->toBe('MHRWS');
});

test('the final archive path uses PA grouping while preserving office custody and legacy office paths', function (): void {
    Storage::fake('local');
    $fixture = pamoArchiveGroupingFixture('MHRWS', 'pamo_direct');
    $gateway = new FakeDocumentArchiveGateway();
    app()->instance(GoogleDriveArchiveGateway::class, $gateway);
    $this->seed(ModuleDefinitionSeeder::class);
    $module = ModuleDefinition::query()->where('code', 'restoration_plan_5_year')->firstOrFail();
    $path = 'qa-archive-grouping/mhrws-final.pdf';
    Storage::disk('local')->put($path, "%PDF-1.7\nQA-only final archive grouping test");
    $archiver = app(FinalDocumentArchiver::class);
    $archiveFinal = function (string $slot, string $office = 'PENRO Davao Oriental') use ($archiver, $fixture, $module, $path): array {
        return $archiver->archiveFinalDocumentUsingAdapter(
            $fixture['record'],
            'conservation',
            $slot,
            new StagedFinalDocumentAdapter($path, 'QA-ONLY-final.pdf'),
            $slot,
            (int) $fixture['user']->getKey(),
            static function (): void {},
            null,
            $module,
            'Conservation Unit',
            $office,
            FinalDocumentArchiveManifest::PHASE,
        );
    };

    expect($archiveFinal('regional-return-final:qa-r1:primary')['status'])->toBe('archived');
    $pamoContext = collect($gateway->archiveContexts())->last()['context'];
    expect($pamoContext['archive_office'])->toBe('PENRO Davao Oriental')
        ->and($pamoContext['archive_folder_group'])->toBe('MHRWS')
        ->and($pamoContext['folder_path'])->toBe(['Conservation Unit', 'MHRWS', $module->name]);

    SubmissionRoutingSnapshot::query()
        ->where('source_key', 'conservation')->where('source_id', $fixture['record']->getKey())->delete();
    $fixture['record']->forceFill(['target_office' => 'CENRO Mati'])->saveOrFail();
    expect($archiveFinal('regional-return-final:legacy-r1:primary', 'CENRO Mati')['status'])->toBe('archived');
    $legacyContext = collect($gateway->archiveContexts())->last()['context'];
    expect($legacyContext['archive_office'])->toBe('CENRO Mati')
        ->and($legacyContext['archive_folder_group'])->toBe('CENRO Mati')
        ->and($legacyContext['folder_path'])->toBe(['Conservation Unit', 'CENRO Mati', $module->name]);
});

test('an interrupted reserved ID is verified at its actual parent without a second upload', function (): void {
    $gateway = new FakeDocumentArchiveGateway();
    $content = "%PDF-1.7\nQA bound destination test";
    $path = tempnam(sys_get_temp_dir(), 'cds-bound-destination-');
    file_put_contents($path, $content);
    $identity = ['source_type' => 'conservation', 'source_id' => 44, 'logical_slot' => 'regional-return-final:receipt-9:r1:primary'];
    $sha256 = hash('sha256', $content);
    $reserved = $gateway->generatePreGeneratedFileId();

    try {
        $first = $gateway->uploadUsingPreGeneratedId($reserved, $path, 'QA-ONLY.pdf', $identity, $sha256, 'legacy-parent', ['folder_path' => ['Conservation Unit', 'PENRO Davao Oriental', 'Conservation']], 'application/pdf');
        $reconciled = $gateway->verifyPreGeneratedObjectAtBoundLocation($reserved, $identity, $sha256, strlen($content));
        $repeated = $gateway->verifyPreGeneratedObjectAtBoundLocation($reserved, $identity, $sha256, strlen($content));

        expect($first['file_id'])->toBe($reserved)
            ->and($reconciled)->toBe(['status' => 'verified', 'file_id' => $reserved, 'folder_id' => 'legacy-parent'])
            ->and($repeated)->toBe($reconciled)
            ->and($gateway->uploadAttempts())->toBe(1);
    } finally {
        @unlink($path);
    }
});

test('missing origin preserves the office path and mismatched captured PA fails closed', function (): void {
    $legacy = pamoArchiveGroupingFixture('LEGACY', 'pamo_direct');
    SubmissionRoutingSnapshot::query()
        ->where('source_key', 'conservation')->where('source_id', $legacy['record']->getKey())->delete();
    $module = new \App\Models\ModuleDefinition(['name' => 'Conservation Reports', 'is_active' => true]);

    expect(app(PamoArchiveFolderGroupingResolver::class)
        ->forFinalReturn('conservation', $legacy['record'], \App\Models\FinalDocumentArchiveManifest::PHASE))
        ->toBeNull()
        ->and(app(\App\Services\Archive\ArchivePathBuilder::class)
            ->build($module, 'Conservation Unit', 'PENRO Davao Oriental', 'legacy', 'pdf', true)['segments'])
        ->toBe(['Conservation Unit', 'PENRO Davao Oriental', 'Conservation Reports']);

    $fixture = pamoArchiveGroupingFixture('MHRWS', 'pamo_direct');
    $fixture['record']->forceFill(['protected_area_id' => $legacy['area']->getKey()])->saveOrFail();

    expect(fn () => app(PamoArchiveFolderGroupingResolver::class)
        ->forFinalReturn('conservation', $fixture['record'], \App\Models\FinalDocumentArchiveManifest::PHASE))
        ->toThrow(RuntimeException::class, 'could not be verified');
});
