<?php

namespace Tests\Feature;

use App\Models\BmsReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Models\FinalDocumentArchiveFile;
use App\Models\FinalDocumentArchiveManifest;
use App\Models\FinalDocumentReceipt;
use App\Models\ModuleDefinition;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Archive\ArchiveCheckpointPolicy;
use App\Services\Archive\FinalDocumentArchiveService;
use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\SharedArchiveHarnessGateway;

final class FinalDocumentArchiveMysqlConcurrencyTest extends \Tests\TestCase
{
    #[Test]
    public function owned_mysql_receipt_archive_claim_and_crash_recovery_boundaries_are_durable(): void
    {
        $owner = $this->assertOwnedSchema();
        $this->configureHarness($owner['task_root']);
        $gateway = app(GoogleDriveArchiveGateway::class);
        self::assertInstanceOf(SharedArchiveHarnessGateway::class, $gateway);

        $fixture = $this->releasedBms('mysql concurrent receipt and manifest');
        $providerAttemptsBeforeReceipt = $gateway->uploadAttempts();
        $eventsBeforeReceipt = $fixture['events_before'];
        $receiptPhysicalTime = now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s');
        [$receiptFirst, $receiptContender] = $this->overlapSourceLockWorkers($owner['task_root'], $fixture, $receiptPhysicalTime);
        self::assertSame('success', $receiptFirst['result']['outcome'] ?? null);
        self::assertSame('success', $receiptContender['result']['outcome'] ?? null);
        self::assertSame($receiptFirst['result']['receipt_id'], $receiptContender['result']['receipt_id']);
        $receipt = FinalDocumentReceipt::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->getKey())->firstOrFail();
        self::assertSame((int) $receiptFirst['result']['receipt_id'], (int) $receipt->getKey());
        self::assertSame(1, FinalDocumentReceipt::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->getKey())->count());
        self::assertSame($providerAttemptsBeforeReceipt, $gateway->uploadAttempts(), 'Receipt writes must never call the archive provider.');
        self::assertSame($eventsBeforeReceipt, DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->getKey())->count());

        $intent = (string) Str::uuid();
        $uploadPath = $owner['task_root'].DIRECTORY_SEPARATOR.'input-concurrent.pdf';
        file_put_contents($uploadPath, "%PDF-1.7\nSynthetic concurrent final return\n%%EOF");
        $pauseSignal = $owner['task_root'].DIRECTORY_SEPARATOR.'control'.DIRECTORY_SEPARATOR.'provider-persisted';
        $pauseRelease = $owner['task_root'].DIRECTORY_SEPARATOR.'control'.DIRECTORY_SEPARATOR.'provider-release';
        $attemptsBeforeArchive = $gateway->uploadAttempts();
        $first = $this->startWorker($owner['task_root'], [
            'mode' => 'archive', 'role' => 'ordinary', 'actor_id' => $fixture['penro_focal']->getKey(),
            'source_id' => $fixture['report']->getKey(), 'intent_key' => $intent,
            'upload_path' => $uploadPath, 'filename' => 'concurrent-final-return.pdf',
        ], ['CDS_ARCHIVE_FAKE_PAUSE_SIGNAL' => $pauseSignal, 'CDS_ARCHIVE_FAKE_PAUSE_RELEASE' => $pauseRelease]);
        $second = null;
        try {
            $this->waitForSignal($pauseSignal, $first);
            $second = $this->startWorker($owner['task_root'], [
                'mode' => 'archive', 'role' => 'ordinary', 'actor_id' => $fixture['penro_focal']->getKey(),
                'source_id' => $fixture['report']->getKey(), 'intent_key' => $intent,
                'upload_path' => $uploadPath, 'filename' => 'concurrent-final-return.pdf',
            ]);
            $secondResult = $this->finishWorker($second, 25);
        } finally {
            file_put_contents($pauseRelease, 'release');
        }
        $firstResult = $this->finishWorker($first, 25);
        self::assertSame(0, $firstResult['exit_code']);
        self::assertSame('archived', $firstResult['result']['status'] ?? null, json_encode($firstResult, JSON_UNESCAPED_SLASHES));
        self::assertSame(0, $secondResult['exit_code']);
        self::assertSame('processing', $secondResult['result']['status'] ?? null);
        $manifest = FinalDocumentArchiveManifest::query()->with('files.archive')->where('intent_key', $intent)->firstOrFail();
        self::assertSame('ARCHIVED', $manifest->archive_status);
        self::assertSame(1, $manifest->files->count());
        self::assertSame('ARCHIVED', $manifest->files->first()->file_status);
        self::assertNotNull($manifest->files->first()->archive?->google_drive_file_id);
        self::assertSame($attemptsBeforeArchive + 1, $gateway->uploadAttempts(), 'The manifest claim must serialize provider uploads for an identical intent.');

        $staleSet = UploadedFile::fake()->createWithContent('stale-revision.pdf', "%PDF-1.7\nChanged after first publication");
        try {
            try {
                app(FinalDocumentArchiveService::class)->createOrRetryManifest(
                    $fixture['penro_focal'], 'bms', (int) $fixture['report']->getKey(), (string) Str::uuid(), [$staleSet], true,
                );
                self::fail('A different file set must not attach to a previously archived receipt revision.');
            } catch (\Illuminate\Validation\ValidationException) {
                self::assertSame(1, FinalDocumentArchiveManifest::query()->where('receipt_id', $receipt->getKey())->count());
                self::assertSame($attemptsBeforeArchive + 1, $gateway->uploadAttempts());
            }
        } finally {
            if (is_file($staleSet->getPathname())) @unlink($staleSet->getPathname());
        }

        $partial = $this->releasedBms('mysql partial multi-file retry');
        $partialReceipt = app(FinalDocumentArchiveService::class)->recordReceipt(
            $partial['penro_focal'], 'bms', (int) $partial['report']->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'),
        );
        $partialIntent = (string) Str::uuid();
        $partialA = $owner['task_root'].DIRECTORY_SEPARATOR.'partial-a.pdf';
        $partialB = $owner['task_root'].DIRECTORY_SEPARATOR.'partial-b.pdf';
        file_put_contents($partialA, "%PDF-1.7\nVerified first partial object\n%%EOF");
        file_put_contents($partialB, "%PDF-1.7\nSecond object retries after rejection\n%%EOF");
        $partialAttempts = $gateway->uploadAttempts();
        $partialFirst = $this->runWorker($owner['task_root'], [
            'mode' => 'archive', 'actor_id' => $partial['penro_focal']->getKey(),
            'source_id' => $partial['report']->getKey(), 'intent_key' => $partialIntent,
            'uploads' => [
                ['path' => $partialA, 'filename' => 'partial-a.pdf'],
                ['path' => $partialB, 'filename' => 'partial-b.pdf'],
            ],
        ], ['CDS_ARCHIVE_FAKE_REJECT_UPLOAD_AT' => (string) ($partialAttempts + 2)]);
        $partialManifest = FinalDocumentArchiveManifest::query()->with('files.archive')->where('intent_key', $partialIntent)->firstOrFail();
        self::assertSame(0, $partialFirst['exit_code']);
        self::assertSame('failed', $partialFirst['result']['status'] ?? null);
        self::assertSame((int) $partialReceipt->getKey(), (int) $partialManifest->receipt_id);
        self::assertSame('FAILED', $partialManifest->archive_status);
        self::assertSame(['ARCHIVED', 'FAILED'], $partialManifest->files->pluck('file_status')->all());
        self::assertNotNull($partialManifest->files->first()->document_archive_id);
        self::assertNull($partialManifest->files->last()->document_archive_id);
        $partialFirstArchiveId = (int) $partialManifest->files->first()->document_archive_id;
        self::assertSame($partialAttempts + 2, $gateway->uploadAttempts());

        $sameIntentOtherSource = $this->releasedBms('mysql intent cannot cross source records');
        $otherReceipt = app(FinalDocumentArchiveService::class)->recordReceipt(
            $sameIntentOtherSource['penro_focal'], 'bms', (int) $sameIntentOtherSource['report']->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'),
        );
        $otherUpload = UploadedFile::fake()->createWithContent('other-source-intent.pdf', "%PDF-1.7\nDifferent report cannot reuse archive intent");
        try {
            try {
                app(FinalDocumentArchiveService::class)->createOrRetryManifest(
                    $sameIntentOtherSource['penro_focal'], 'bms', (int) $sameIntentOtherSource['report']->getKey(), $partialIntent, [$otherUpload], true,
                );
                self::fail('An archive intent already bound to another source record must not be reused.');
            } catch (\Illuminate\Validation\ValidationException) {
                self::assertSame(0, FinalDocumentArchiveManifest::query()->where('receipt_id', $otherReceipt->getKey())->count());
                self::assertSame($partialAttempts + 2, $gateway->uploadAttempts());
            }
        } finally {
            if (is_file($otherUpload->getPathname())) @unlink($otherUpload->getPathname());
        }

        $partialRetry = $this->runWorker($owner['task_root'], [
            'mode' => 'retry', 'actor_id' => $partial['penro_focal']->getKey(), 'manifest_id' => $partialManifest->getKey(),
        ]);
        $partialManifest->refresh()->load('files.archive');
        self::assertSame(0, $partialRetry['exit_code']);
        self::assertSame('archived', $partialRetry['result']['status'] ?? null);
        self::assertSame('ARCHIVED', $partialManifest->archive_status);
        self::assertSame(['ARCHIVED', 'ARCHIVED'], $partialManifest->files->pluck('file_status')->all());
        self::assertSame($partialFirstArchiveId, (int) $partialManifest->files->first()->document_archive_id);
        self::assertNotNull($partialManifest->files->last()->document_archive_id);
        self::assertNotSame($partialFirstArchiveId, (int) $partialManifest->files->last()->document_archive_id);
        self::assertSame($partialAttempts + 3, $gateway->uploadAttempts(), 'Retry must retain the verified first object and upload only the unfinished second file.');

        $lost = $this->releasedBms('mysql lost response recovery');
        $lostReceipt = app(FinalDocumentArchiveService::class)->recordReceipt($lost['penro_focal'], 'bms', (int) $lost['report']->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'));
        $lostUpload = UploadedFile::fake()->createWithContent('lost-response-final.pdf', "%PDF-1.7\nProvider persisted then lost response");
        $lostIntent = (string) Str::uuid();
        $beforeLost = $gateway->uploadAttempts();
        putenv('CDS_ARCHIVE_FAKE_FAULT=lost_response');
        try {
            $failed = app(FinalDocumentArchiveService::class)->createOrRetryManifest($lost['penro_focal'], 'bms', (int) $lost['report']->getKey(), $lostIntent, [$lostUpload], true);
        } finally {
            putenv('CDS_ARCHIVE_FAKE_FAULT=');
            if (is_file($lostUpload->getPathname())) @unlink($lostUpload->getPathname());
        }
        $lostManifest = FinalDocumentArchiveManifest::query()->with('files.archive')->where('intent_key', $lostIntent)->firstOrFail();
        self::assertSame('failed', $failed['status']);
        self::assertSame('FAILED', $lostManifest->archive_status);
        self::assertNull($lostManifest->files->first()->document_archive_id);
        self::assertSame($beforeLost + 1, $gateway->uploadAttempts());
        self::assertSame((int) $lostReceipt->getKey(), (int) $lostManifest->receipt_id);
        $lostObjectIdsBeforeRetry = array_keys($gateway->objects());
        $retry = app(FinalDocumentArchiveService::class)->retry($lost['penro_focal'], (int) $lostManifest->getKey());
        $lostManifest->refresh()->load('files.archive');
        self::assertSame('archived', $retry['status']);
        self::assertSame('ARCHIVED', $lostManifest->archive_status);
        self::assertSame($beforeLost + 1, $gateway->uploadAttempts(), 'Exact identity search must reuse the persisted object after a lost response.');
        self::assertNotEmpty(array_intersect($lostObjectIdsBeforeRetry, array_keys($gateway->objects())));

        $crashed = $this->releasedBms('mysql worker exits after provider persistence');
        $crashedReceipt = app(FinalDocumentArchiveService::class)->recordReceipt($crashed['penro_focal'], 'bms', (int) $crashed['report']->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'));
        $crashIntent = (string) Str::uuid();
        $crashUpload = $owner['task_root'].DIRECTORY_SEPARATOR.'input-crash.pdf';
        file_put_contents($crashUpload, "%PDF-1.7\nPersisted object before PHP worker exit\n%%EOF");
        $beforeCrash = $gateway->uploadAttempts();
        $crashWorker = $this->startWorker($owner['task_root'], [
            'mode' => 'archive', 'role' => 'ordinary', 'actor_id' => $crashed['penro_focal']->getKey(),
            'source_id' => $crashed['report']->getKey(), 'intent_key' => $crashIntent,
            'upload_path' => $crashUpload, 'filename' => 'crash-final-return.pdf',
        ], ['CDS_ARCHIVE_FAKE_FAULT' => 'crash_after_upload']);
        $crashResult = $this->finishWorker($crashWorker, 25);
        self::assertSame(86, $crashResult['exit_code']);
        $crashManifest = FinalDocumentArchiveManifest::query()->with('files')->where('intent_key', $crashIntent)->firstOrFail();
        self::assertSame('PROCESSING', $crashManifest->archive_status);
        self::assertSame('UPLOADING', $crashManifest->files->first()->file_status);
        self::assertNull($crashManifest->files->first()->document_archive_id);
        self::assertSame((int) $crashedReceipt->getKey(), (int) $crashManifest->receipt_id);
        $crashFileId = array_key_first(array_filter($gateway->objects(), fn (array $object): bool => ($object['identity']['source_id'] ?? null) === (int) $crashed['report']->getKey() && str_contains((string) ($object['identity']['logical_slot'] ?? ''), 'regional-return-final')));
        self::assertNotNull($crashFileId, 'The fake provider must durably retain the object before simulating process loss.');
        FinalDocumentArchiveManifest::query()->whereKey($crashManifest->getKey())->update(['claimed_at' => now()->subMinutes(45)]);
        FinalDocumentArchiveFile::query()->where('manifest_id', $crashManifest->getKey())->update(['claimed_at' => now()->subMinutes(45)]);
        $crashRetry = $this->runWorker($owner['task_root'], [
            'mode' => 'retry', 'role' => 'ordinary', 'actor_id' => $crashed['penro_focal']->getKey(), 'manifest_id' => $crashManifest->getKey(),
        ]);
        $crashManifest->refresh()->load('files.archive');
        self::assertSame('archived', $crashRetry['result']['status'] ?? null);
        self::assertSame('ARCHIVED', $crashManifest->archive_status);
        self::assertSame($crashFileId, $crashManifest->files->first()->archive?->google_drive_file_id);
        self::assertSame($beforeCrash + 1, $gateway->uploadAttempts(), 'A crashed worker retry must reconcile the exact durable provider object.');

        $uncertain = $this->releasedBms('mysql inconclusive identity search');
        app(FinalDocumentArchiveService::class)->recordReceipt($uncertain['penro_focal'], 'bms', (int) $uncertain['report']->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'));
        $uncertainUpload = UploadedFile::fake()->createWithContent('uncertain-search.pdf', "%PDF-1.7\nProvider search unknown");
        $uncertainIntent = (string) Str::uuid();
        $beforeUncertain = $gateway->uploadAttempts();
        putenv('CDS_ARCHIVE_FAKE_FAULT=find_uncertain');
        try {
            $uncertainResult = app(FinalDocumentArchiveService::class)->createOrRetryManifest($uncertain['penro_focal'], 'bms', (int) $uncertain['report']->getKey(), $uncertainIntent, [$uncertainUpload], true);
        } finally {
            putenv('CDS_ARCHIVE_FAKE_FAULT=');
            if (is_file($uncertainUpload->getPathname())) @unlink($uncertainUpload->getPathname());
        }
        $uncertainManifest = FinalDocumentArchiveManifest::query()->with('files')->where('intent_key', $uncertainIntent)->firstOrFail();
        self::assertSame('failed', $uncertainResult['status']);
        self::assertSame('FAILED', $uncertainManifest->archive_status);
        self::assertSame('FAILED', $uncertainManifest->files->first()->file_status);
        self::assertSame($beforeUncertain, $gateway->uploadAttempts(), 'An inconclusive identity search must not guess that a new upload is safe.');
        self::assertSame(1, FinalDocumentReceipt::query()->where('source_type', 'bms')->where('source_id', $uncertain['report']->getKey())->count());

        $mismatch = $this->releasedBms('mysql verification mismatch');
        app(FinalDocumentArchiveService::class)->recordReceipt($mismatch['penro_focal'], 'bms', (int) $mismatch['report']->getKey(), now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'));
        $mismatchUpload = UploadedFile::fake()->createWithContent('verification-mismatch.pdf', "%PDF-1.7\nVerification mismatch");
        $mismatchIntent = (string) Str::uuid();
        $beforeMismatch = $gateway->uploadAttempts();
        putenv('CDS_ARCHIVE_FAKE_FAULT=verification_mismatch');
        try {
            $mismatchResult = app(FinalDocumentArchiveService::class)->createOrRetryManifest($mismatch['penro_focal'], 'bms', (int) $mismatch['report']->getKey(), $mismatchIntent, [$mismatchUpload], true);
        } finally {
            putenv('CDS_ARCHIVE_FAKE_FAULT=');
            if (is_file($mismatchUpload->getPathname())) @unlink($mismatchUpload->getPathname());
        }
        $mismatchManifest = FinalDocumentArchiveManifest::query()->with('files')->where('intent_key', $mismatchIntent)->firstOrFail();
        self::assertSame('failed', $mismatchResult['status']);
        self::assertSame('FAILED', $mismatchManifest->archive_status);
        self::assertSame($beforeMismatch + 1, $gateway->uploadAttempts());
        self::assertNotSame('ARCHIVED', $mismatchManifest->files->first()->file_status);
        self::assertSame(1, FinalDocumentReceipt::query()->whereKey($mismatchManifest->receipt_id)->count());

        $wrongOfficeActor = $this->archiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Wrong Office');
        $uploadsBeforeWrongOffice = $gateway->uploadAttempts();
        try {
            app(FinalDocumentArchiveService::class)->recordReceipt($wrongOfficeActor, 'bms', (int) $fixture['report']->getKey(), now('Asia/Manila')->addMinutes(4)->format('Y-m-d H:i:s'));
            self::fail('A foreign PENRO focal must not receive a report assigned to PENRO Davao Oriental.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            self::assertSame($uploadsBeforeWrongOffice, $gateway->uploadAttempts());
        }

        $this->assertSourceLockFailureIsFailClosed($owner['task_root']);
    }

    private function assertOwnedSchema(): array
    {
        self::assertSame('testing', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertSame('1', getenv('CDS_ARCHIVE_MYSQL_INTEGRATION'));
        self::assertSame((string) config('database.connections.mysql.database'), (string) getenv('CDS_ARCHIVE_MYSQL_TEST_DB'));
        self::assertEmpty(config('database.connections.mysql.url'));
        $taskRoot = realpath((string) getenv('CDS_ARCHIVE_TASK_ROOT')) ?: '';
        $expectedRoot = realpath(base_path('.tmp')).DIRECTORY_SEPARATOR.'cds-final-archive-mysql-';
        self::assertStringStartsWith($expectedRoot, $taskRoot);
        $owner = json_decode((string) file_get_contents($taskRoot.DIRECTORY_SEPARATOR.'ownership.json'), true);
        self::assertIsArray($owner);
        self::assertSame($taskRoot, $owner['task_root'] ?? null);
        self::assertSame(config('database.connections.mysql.database'), $owner['schema'] ?? null);
        self::assertSame(getenv('CDS_ARCHIVE_TASK_ID'), $owner['task_id'] ?? null);
        self::assertSame(getenv('CDS_ARCHIVE_TASK_MARKER'), $owner['marker'] ?? null);
        self::assertMatchesRegularExpression('/^cds_archive_uat_20261014_[a-f0-9]{8}$/', (string) $owner['schema']);
        $identity = DB::connection()->selectOne('SELECT DATABASE() AS database_name, @@server_uuid AS server_uuid, @@transaction_isolation AS isolation_level');
        self::assertSame($owner['schema'], $identity->database_name);
        self::assertSame($owner['server_uuid'], $identity->server_uuid);
        self::assertSame('REPEATABLE-READ', $identity->isolation_level);
        $hash = DB::table('cds_task_ownership_marker')->where('task_id', $owner['task_id'])->value('marker_sha256');
        self::assertTrue(hash_equals(hash('sha256', $owner['marker']), (string) $hash));
        foreach (['final_document_receipts', 'final_document_archive_manifests', 'final_document_archive_files'] as $table) self::assertTrue(Schema::hasTable($table), $table.' must exist in the owned migrated schema.');
        return ['task_root' => $taskRoot, 'task_id' => $owner['task_id'], 'marker' => $owner['marker'], 'server_uuid' => $owner['server_uuid'], 'schema' => $owner['schema']];
    }

    private function configureHarness(string $taskRoot): void
    {
        foreach (['control', 'private-storage'] as $directory) {
            $path = $taskRoot.DIRECTORY_SEPARATOR.$directory;
            if (! is_dir($path)) mkdir($path, 0700, true);
        }
        config([
            'services.document_archive.driver' => 'fake',
            'services.document_archive.final_return_archive_enabled' => true,
            'services.google_drive_archive.enabled' => true,
            'filesystems.disks.local.root' => $taskRoot.DIRECTORY_SEPARATOR.'private-storage',
            'cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'mail.default' => 'array',
        ]);
        Storage::forgetDisk('local');
        Notification::fake();
        app()->instance(GoogleDriveArchiveGateway::class, new SharedArchiveHarnessGateway());
    }

    private function releasedBms(string $name): array
    {
        ModuleDefinition::query()->where('code', 'bms')->update(['is_active' => true]);
        $owner = User::factory()->create();
        $area = ProtectedArea::query()->create([
            'name' => $name.' PA', 'short_name' => strtoupper(substr(hash('sha256', $name.microtime(true)), 0, 8)),
            'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental',
            'region' => 'Region XI', 'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
        ]);
        $cenro = OrganizationalOffice::query()->where('code', 'cenro_mati')->firstOrFail();
        $penro = OrganizationalOffice::query()->where('code', 'penro_davao_oriental')->firstOrFail();
        ProtectedAreaOfficeAssignment::query()->create(['protected_area_id' => $area->getKey(), 'organizational_office_id' => $cenro->getKey(), 'assignment_type' => 'supervising', 'assigned_by' => $owner->getKey()]);
        $report = BmsReportSubmission::query()->create([
            'protected_area_id' => $area->getKey(), 'target_office' => 'CENRO Mati', 'activity_name' => $name,
            'document_type' => 'Report', 'semester' => '1st Semester', 'date_accomplished' => '2026-10-01',
            'created_by' => $owner->getKey(), 'updated_by' => $owner->getKey(),
        ]);
        $path = 'bms-report-movs/mysql-harness-'.$report->getKey().'.pdf';
        $report->forceFill(['mov_file_path' => $path, 'mov_file_name' => 'Synthetic source report.pdf'])->saveOrFail();
        Storage::disk('local')->put($path, "%PDF-1.7\nSynthetic unsigned source for {$name}\n%%EOF");
        $actors = [
            'cenro_focal' => $this->archiveActor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati'),
            'cenro_chief' => $this->archiveActor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
            'cenro_records' => $this->archiveActor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
            'penro_records' => $this->archiveActor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
            'office' => $this->archiveActor(OrganizationalAccessService::OFFICE_PENRO, 'PENRO Davao Oriental'),
            'tsd' => $this->archiveActor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
            'penro_focal' => $this->archiveActor(OrganizationalAccessService::PENRO_FOCAL, 'PENRO Davao Oriental'),
            'penro_chief' => $this->archiveActor(OrganizationalAccessService::PENRO_CHIEF, 'PENRO Davao Oriental'),
        ];
        $routing = app(DocumentRoutingTransitionService::class);
        foreach ([
            ['cenro_focal', 'forward_to_cenro_chief'], ['cenro_chief', 'receive_at_cenro_chief'],
            ['cenro_chief', 'forward_to_cenro_records'], ['cenro_records', 'receive_at_cenro_records'],
            ['cenro_records', 'forward_to_penro_records'], ['penro_records', 'receive_at_penro_records'],
            ['penro_records', 'forward_to_office_penro'], ['office', 'receive_at_office_penro'],
            ['office', 'assign_to_tsd_chief'], ['tsd', 'receive_at_tsd_chief'],
            ['tsd', 'forward_to_cds_focal'], ['penro_focal', 'receive_at_cds_focal'],
            ['penro_focal', 'forward_to_cds_chief'], ['penro_chief', 'receive_at_cds_chief'],
            ['penro_chief', 'recommend_to_office_penro'], ['office', 'receive_at_office_penro_final'],
            ['office', 'approve_for_regional_release'], ['penro_records', 'receive_at_penro_records_final'],
            ['penro_records', 'release_to_regional'],
        ] as [$key, $action]) {
            $routing->transition($report->fresh(), 'bms', $action, $actors[$key]->getKey());
        }
        return [
            'report' => $report->fresh(), 'penro_focal' => $actors['penro_focal'],
            'events_before' => DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->count(),
        ];
    }

    private function archiveActor(string $category, string $office): User
    {
        $actor = User::factory()->create([
            'section' => $category, 'unit_assignment' => OrganizationalAccessService::CONSERVATION,
            'office_designated' => $office, 'protected_area_id' => null, 'is_active' => true, 'is_approved' => true,
        ]);
        foreach (['reports.view', 'bms.update', 'submission-tracking.view', 'technical-reports.view', 'technical-reports.update'] as $permission) {
            $actor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
        return $actor;
    }

    private function overlapSourceLockWorkers(string $taskRoot, array $fixture, string $physicalTime): array
    {
        $control = $taskRoot.DIRECTORY_SEPARATOR.'control';
        $firstSignal = $control.DIRECTORY_SEPARATOR.'receipt-first-locked';
        $contenderSignal = $control.DIRECTORY_SEPARATOR.'receipt-contender-attempt';
        $release = $control.DIRECTORY_SEPARATOR.'receipt-release';
        $first = $this->startWorker($taskRoot, [
            'mode' => 'receive', 'role' => 'first', 'signal' => $firstSignal, 'release' => $release,
            'actor_id' => $fixture['penro_focal']->getKey(), 'source_id' => $fixture['report']->getKey(), 'physical_time' => $physicalTime,
        ]);
        $second = null;
        try {
            $this->waitForSignal($firstSignal, $first);
            $second = $this->startWorker($taskRoot, [
                'mode' => 'receive', 'role' => 'contender', 'signal' => $contenderSignal,
                'actor_id' => $fixture['penro_focal']->getKey(), 'source_id' => $fixture['report']->getKey(), 'physical_time' => $physicalTime,
            ]);
            $this->waitForSignal($contenderSignal, $second);
            usleep(250000);
            self::assertTrue(proc_get_status($second['process'])['running'], 'The second receipt request must wait for the source row lock.');
        } finally {
            file_put_contents($release, 'release');
        }
        return [$this->finishWorker($first, 25), $this->finishWorker($second, 25)];
    }

    private function assertSourceLockFailureIsFailClosed(string $taskRoot): void
    {
        $fixture = $this->releasedBms('mysql source lock timeout');
        $control = $taskRoot.DIRECTORY_SEPARATOR.'control';
        $held = $control.DIRECTORY_SEPARATOR.'timeout-holder-locked';
        $attempt = $control.DIRECTORY_SEPARATOR.'timeout-contender-attempt';
        $release = $control.DIRECTORY_SEPARATOR.'timeout-release';
        $before = app(GoogleDriveArchiveGateway::class)->uploadAttempts();
        $holder = $this->startWorker($taskRoot, ['mode' => 'hold_source_lock', 'source_id' => $fixture['report']->getKey(), 'signal' => $held, 'release' => $release]);
        $contender = null;
        try {
            $this->waitForSignal($held, $holder);
            $contender = $this->startWorker($taskRoot, [
                'mode' => 'receive', 'role' => 'contender', 'signal' => $attempt,
                'actor_id' => $fixture['penro_focal']->getKey(), 'source_id' => $fixture['report']->getKey(),
                'physical_time' => now('Asia/Manila')->addMinutes(3)->format('Y-m-d H:i:s'),
            ], ['CDS_ARCHIVE_LOCK_WAIT_SECONDS' => '2']);
            $this->waitForSignal($attempt, $contender);
            $failed = $this->finishWorker($contender, 10);
        } finally {
            file_put_contents($release, 'release');
        }
        $this->finishWorker($holder, 25);
        self::assertSame(1, $failed['exit_code']);
        self::assertSame('error', $failed['result']['outcome'] ?? null);
        self::assertSame(0, FinalDocumentReceipt::query()->where('source_type', 'bms')->where('source_id', $fixture['report']->getKey())->count());
        self::assertSame($before, app(GoogleDriveArchiveGateway::class)->uploadAttempts());
    }

    private function runWorker(string $taskRoot, array $payload, array $extraEnvironment = []): array
    {
        $worker = $this->startWorker($taskRoot, $payload, $extraEnvironment);
        return $this->finishWorker($worker, 25);
    }

    private function startWorker(string $taskRoot, array $payload, array $extraEnvironment = []): array
    {
        $script = base_path('tests/Support/final_document_archive_mysql_worker.php');
        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $pipes = [];
        $process = proc_open([PHP_BINARY, $script, $encoded], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $this->workerEnvironment($taskRoot, $extraEnvironment));
        if (! is_resource($process)) self::fail('Could not start a separate archive MySQL worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function workerEnvironment(string $taskRoot, array $extraEnvironment = []): array
    {
        $owner = json_decode((string) file_get_contents($taskRoot.DIRECTORY_SEPARATOR.'ownership.json'), true);
        $environment = getenv();
        if (! is_array($environment)) $environment = [];
        $environment = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $environment);
        $environment = array_replace($environment, [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_URL' => '',
            'CDS_ARCHIVE_MYSQL_INTEGRATION' => '1', 'CDS_ARCHIVE_MYSQL_TEST_DB' => (string) config('database.connections.mysql.database'),
            'CDS_ARCHIVE_TASK_ROOT' => $taskRoot, 'CDS_ARCHIVE_TASK_ID' => (string) ($owner['task_id'] ?? ''),
            'CDS_ARCHIVE_TASK_MARKER' => (string) ($owner['marker'] ?? ''),
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
        ]);
        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD', 'unix_socket' => 'DB_SOCKET'] as $key => $name) {
            $value = config('database.connections.mysql.'.$key);
            if ($value !== null) $environment[$name] = (string) $value;
        }
        return array_replace($environment, $extraEnvironment);
    }

    private function waitForSignal(string $signal, array &$worker): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($signal) && microtime(true) < $deadline) {
            if (! proc_get_status($worker['process'])['running']) {
                $worker['early_output'] = (string) stream_get_contents($worker['stdout']);
                self::fail('Archive MySQL worker ended before its synchronization barrier.');
            }
            usleep(10000);
        }
        self::assertFileExists($signal, 'Archive MySQL worker missed a bounded overlap signal.');
    }

    private function finishWorker(array &$worker, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $status = proc_get_status($worker['process']);
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(20000);
            $status = proc_get_status($worker['process']);
        }
        if ($status['running']) {
            proc_terminate($worker['process']);
            self::fail('Archive MySQL worker exceeded its bounded timeout.');
        }
        $stdout = (string) stream_get_contents($worker['stdout']);
        $stderr = (string) stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $closeCode = proc_close($worker['process']);
        $exitCode = (int) ($status['exitcode'] >= 0 ? $status['exitcode'] : $closeCode);
        $result = json_decode(trim($stdout), true);
        return ['exit_code' => $exitCode, 'result' => is_array($result) ? $result : null, 'stderr_bytes' => strlen($stderr), 'stdout' => trim($stdout)];
    }
}
