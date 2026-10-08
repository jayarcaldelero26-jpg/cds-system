<?php

namespace Tests\Feature;

use App\Models\BmsReportSubmission;
use App\Models\DocumentArchive;
use App\Models\DocumentRoutingEvent;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\RoutingPositionSetting;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\RoutingPositionSettingsService;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Destructive only to a uniquely named, explicitly opted-in disposable MySQL database.
 * Run with CDS_ROUTING_MYSQL_INTEGRATION=1 and CDS_ROUTING_MYSQL_TEST_DB=cds_dispatch_guard_<unique>.
 */
final class DispatchInProgressMysqlConcurrencyTest extends \Tests\TestCase
{
    public function test_concurrent_initial_dispatches_are_single_write_and_retryable(): void
    {
        $database = (string) config('database.connections.mysql.database');
        if (getenv('CDS_ROUTING_MYSQL_INTEGRATION') !== '1'
            || app()->environment() !== 'testing'
            || config('database.default') !== 'mysql'
            || ! str_starts_with($database, 'cds_dispatch_guard_')
            || getenv('CDS_ROUTING_MYSQL_TEST_DB') !== $database) {
            $this->markTestSkipped('Requires the explicitly named disposable routing-guard MySQL database.');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);

        $taskRoot = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'cds-routing-dispatch-guard-'.bin2hex(random_bytes(8));
        $storageRoot = $taskRoot.DIRECTORY_SEPARATOR.'storage';
        $cacheRoot = $taskRoot.DIRECTORY_SEPARATOR.'cache';
        $controlRoot = $taskRoot.DIRECTORY_SEPARATOR.'control';
        foreach ([$storageRoot, $cacheRoot, $controlRoot] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                self::fail('Could not create the isolated dispatch test directory.');
            }
        }

        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $cacheRoot,
            'cache.stores.file.lock_path' => $cacheRoot,
            'filesystems.disks.local.root' => $storageRoot,
            'logging.default' => 'stderr',
            'services.google_drive_archive.enabled' => true,
            'services.document_archive.driver' => 'fake',
        ]);
        \Illuminate\Support\Facades\Cache::setDefaultDriver('file');
        \Illuminate\Support\Facades\Cache::purge('file');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::forgetDisk('local');
        $timings = [];

        try {
            $actors = $this->actors();
            $area = $this->protectedArea($actors['owner']);
            $actors['out_of_scope_records'] = $this->actor(
                OrganizationalAccessService::PENRO_RECORDS,
                'PENRO Davao Oriental',
                (int) $area->getKey(),
            );
            $settingsAdmin = $actors['settings_admin'];
            $destinations = [
                ['office' => true, 'tsd' => true, 'action' => 'forward_to_office_penro'],
                ['office' => false, 'tsd' => true, 'action' => 'dispatch_penro_records_to_tsd'],
                ['office' => false, 'tsd' => false, 'action' => 'dispatch_penro_records_to_cds_focal'],
            ];

            foreach ($destinations as $destination) {
                $settings = app(RoutingPositionSettingsService::class)->current();
                if ($settings['office_penro_enabled'] !== $destination['office']
                    || $settings['penro_tsd_chief_enabled'] !== $destination['tsd']) {
                    $settings = app(RoutingPositionSettingsService::class)->save(
                        $settings['version'],
                        $destination['office'],
                        $destination['tsd'],
                        'Isolated concurrent dispatch regression fixture',
                        $settingsAdmin,
                    );
                }

                $settingVersionId = (int) RoutingPositionSetting::query()->whereKey(1)->value('setting_version_id');
                $report = $this->report($area, $actors['owner']);
                $this->routeToPenroRecords($report, $actors);
                $snapshot = SubmissionRoutingSnapshot::query()
                    ->where('source_key', 'bms')->where('source_id', $report->getKey())->firstOrFail();
                $this->assertSame($settingVersionId, (int) $snapshot->setting_version_id);
                $this->assertTrue(collect(app(DocumentRoutingTransitionService::class)
                    ->state($report->fresh()->load('protectedArea'), 'bms')['actions'])->contains('key', $destination['action']));

                $scenario = 'concurrent-'.$report->getKey();
                @unlink($controlRoot.DIRECTORY_SEPARATOR.'upload-'.$scenario.'.started');
                $first = $this->startWorker($this->workerPayload($report, $actors['penro_records'], $destination['action'], $scenario, $taskRoot, false));
                $this->waitForUploadSignal($first, $controlRoot.DIRECTORY_SEPARATOR.'upload-'.$scenario.'.started');
                $second = $this->startWorker($this->workerPayload($report, $actors['penro_records'], $destination['action'], $scenario, $taskRoot, false));

                $secondResult = $this->finishWorker($second, 12);
                $firstResult = $this->finishWorker($first, 12);
                $this->assertSame('success', $firstResult['result'] ?? null, json_encode($firstResult));
                $this->assertSame(302, $firstResult['status'] ?? null);
                $this->assertSame('validation', $secondResult['result'] ?? null, json_encode($secondResult));
                $this->assertStringContainsString('Dispatch already in progress', (string) ($secondResult['message'] ?? ''));
                $this->assertLessThan(1200, (float) ($secondResult['duration_ms'] ?? INF), 'The duplicate waited on the routing transaction.');
                $timings[] = [
                    'action' => $destination['action'],
                    'winning_request_ms' => round((float) ($firstResult['duration_ms'] ?? 0), 2),
                    'duplicate_request_ms' => round((float) ($secondResult['duration_ms'] ?? 0), 2),
                    'fake_upload_delay_ms' => 1800,
                ];

                $events = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->get();
                $this->assertSame(7, $events->count());
                $dispatchEvents = $events->filter(fn (DocumentRoutingEvent $event): bool => data_get($event->metadata, 'action_key') === $destination['action']);
                $this->assertSame(1, $dispatchEvents->count());
                $dispatch = $dispatchEvents->first();
                $this->assertSame((int) $settings['version'], (int) data_get($dispatch?->metadata, 'route_setting_version'));
                $this->assertSame((int) $snapshot->getKey(), (int) data_get($dispatch?->metadata, 'route_snapshot_id'));
                $archive = DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $report->getKey())->firstOrFail();
                $this->assertSame('ARCHIVED', $archive->archive_status);
                $this->assertSame('verified', $archive->remote_availability);
                $this->assertSame(hash_file('sha256', Storage::disk('local')->path($report->mov_file_path)), $archive->archived_sha256);
                $this->assertSame(1, $this->uploadCount($controlRoot, $scenario));
            }

            $retrySettings = app(RoutingPositionSettingsService::class)->current();
            $retryReport = $this->report($area, $actors['owner']);
            $this->routeToPenroRecords($retryReport, $actors);
            $retrySnapshot = SubmissionRoutingSnapshot::query()
                ->where('source_key', 'bms')->where('source_id', $retryReport->getKey())->firstOrFail();
            $retryAction = $retrySettings['office_penro_enabled']
                ? 'forward_to_office_penro'
                : ($retrySettings['penro_tsd_chief_enabled'] ? 'dispatch_penro_records_to_tsd' : 'dispatch_penro_records_to_cds_focal');
            $scenario = 'retry-'.$retryReport->getKey();

            $wrongActor = $this->workerPayload($retryReport, $actors['tsd_chief'], $retryAction, $scenario, $taskRoot, false);
            $wrongActorResult = $this->runWorker($wrongActor, 12);
            $this->assertSame('http_error', $wrongActorResult['result'] ?? null);
            $this->assertSame(403, $wrongActorResult['status'] ?? null);
            $this->assertSame(0, $this->uploadCount($controlRoot, $scenario));

            $outOfScope = $this->workerPayload($retryReport, $actors['out_of_scope_records'], $retryAction, $scenario, $taskRoot, false);
            $outOfScopeResult = $this->runWorker($outOfScope, 12);
            $this->assertSame('http_error', $outOfScopeResult['result'] ?? null);
            $this->assertSame(403, $outOfScopeResult['status'] ?? null);
            $this->assertSame(0, $this->uploadCount($controlRoot, $scenario));

            $failed = $this->runWorker($this->workerPayload($retryReport, $actors['penro_records'], $retryAction, $scenario, $taskRoot, true), 12);
            $this->assertSame('validation', $failed['result'] ?? null);
            $this->assertSame(6, DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $retryReport->getKey())->count());
            $this->assertSame(0, DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $retryReport->getKey())->count());

            $retried = $this->runWorker($this->workerPayload($retryReport, $actors['penro_records'], $retryAction, $scenario, $taskRoot, false), 12);
            $this->assertSame('success', $retried['result'] ?? null, json_encode($retried));
            $this->assertSame(302, $retried['status'] ?? null);
            $this->assertSame(7, DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $retryReport->getKey())->count());
            $this->assertSame('ARCHIVED', DocumentArchive::query()->where('source_type', 'bms')->where('source_id', $retryReport->getKey())->value('archive_status'));
            $this->assertSame(2, $this->uploadCount($controlRoot, $scenario));
            $dispatch = DocumentRoutingEvent::query()->where('source_type', 'bms')->where('source_id', $retryReport->getKey())->get()
                ->first(fn (DocumentRoutingEvent $event): bool => data_get($event->metadata, 'action_key') === $retryAction);
            $this->assertNotNull($dispatch);
            $this->assertSame((int) $retrySnapshot->setting_version_id, (int) data_get($dispatch->metadata, 'route_setting_version_id'));
            $this->assertSame((int) $retrySnapshot->getKey(), (int) data_get($dispatch->metadata, 'route_snapshot_id'));
            $timings[] = [
                'provider_failure_request_ms' => round((float) ($failed['duration_ms'] ?? 0), 2),
                'retry_request_ms' => round((float) ($retried['duration_ms'] ?? 0), 2),
                'fake_upload_delay_ms' => 1800,
            ];
            if (getenv('CDS_ROUTING_MYSQL_TIMINGS') === '1') {
                fwrite(STDERR, 'dispatch-guard-timings='.json_encode($timings, JSON_THROW_ON_ERROR).PHP_EOL);
            }
        } finally {
            Storage::forgetDisk('local');
            \Illuminate\Support\Facades\File::deleteDirectory($taskRoot);
        }
    }

    /** @return array<string,User> */
    private function actors(): array
    {
        $owner = $this->actor(OrganizationalAccessService::CENRO_FOCAL, 'CENRO Mati');
        $admin = User::factory()->create(['section' => 'CDS', 'is_active' => true, 'is_approved' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('submission-tracking.routing-settings.update', 'web'));

        return [
            'owner' => $owner,
            'settings_admin' => $admin,
            'cenro_focal' => $owner,
            'cenro_chief' => $this->actor(OrganizationalAccessService::CENRO_CHIEF, 'CENRO Mati'),
            'cenro_records' => $this->actor(OrganizationalAccessService::CENRO_RECORDS, 'CENRO Mati'),
            'penro_records' => $this->actor(OrganizationalAccessService::PENRO_RECORDS, 'PENRO Davao Oriental'),
            'tsd_chief' => $this->actor(OrganizationalAccessService::PENRO_TSD_CHIEF, 'PENRO Davao Oriental'),
        ];
    }

    private function actor(string $category, string $office, ?int $protectedAreaId = null): User
    {
        $actor = User::factory()->create([
            'section' => $category,
            'office_designated' => $office,
            'unit_assignment' => OrganizationalAccessService::CONSERVATION,
            'protected_area_id' => $protectedAreaId,
            'is_active' => true,
            'is_approved' => true,
        ]);
        foreach (['bms.update', 'submission-tracking.view'] as $ability) {
            $actor->givePermissionTo(Permission::findOrCreate($ability, 'web'));
        }
        return $actor;
    }

    private function protectedArea(User $owner): ProtectedArea
    {
        $area = ProtectedArea::query()->create([
            'name' => 'Dispatch guard isolated test area',
            'short_name' => 'DGIT',
            'category' => 'Protected Landscape',
            'municipality' => 'Mati',
            'province' => 'Davao Oriental',
            'region' => 'Region XI',
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ]);
        ProtectedAreaOfficeAssignment::query()->create([
            'protected_area_id' => $area->getKey(),
            'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
        ]);
        return $area;
    }

    private function report(ProtectedArea $area, User $owner): BmsReportSubmission
    {
        $report = BmsReportSubmission::query()->create([
            'protected_area_id' => $area->getKey(),
            'target_office' => 'CENRO Mati',
            'activity_name' => 'Isolated dispatch concurrency fixture',
            'document_type' => 'Report',
            'semester' => '1st Semester',
            'date_accomplished' => '2026-08-03',
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ]);
        $path = 'bms-report-movs/dispatch-guard-'.$report->getKey().'.pdf';
        $report->forceFill(['mov_file_path' => $path, 'mov_file_name' => 'Synthetic dispatch fixture.pdf'])->save();
        Storage::disk('local')->put($path, "%PDF-1.4\nSynthetic isolated dispatch fixture {$report->getKey()}");
        return $report->fresh();
    }

    /** @param array<string,User> $actors */
    private function routeToPenroRecords(BmsReportSubmission $report, array $actors): void
    {
        $routing = app(DocumentRoutingTransitionService::class);
        foreach ([
            [$actors['cenro_focal'], 'forward_to_cenro_chief'],
            [$actors['cenro_chief'], 'receive_at_cenro_chief'],
            [$actors['cenro_chief'], 'forward_to_cenro_records'],
            [$actors['cenro_records'], 'receive_at_cenro_records'],
            [$actors['cenro_records'], 'forward_to_penro_records'],
            [$actors['penro_records'], 'receive_at_penro_records'],
        ] as [$actor, $action]) {
            $routing->transition($report->fresh(), 'bms', $action, (int) $actor->getKey());
        }
    }

    private function workerPayload(BmsReportSubmission $report, User $actor, string $action, string $scenario, string $taskRoot, bool $failUpload): array
    {
        return [
            'record_id' => (int) $report->getKey(),
            'actor_id' => (int) $actor->getKey(),
            'action' => $action,
            'scenario' => $scenario,
            'task_root' => $taskRoot,
            'delay_ms' => 1800,
            'fail_upload' => $failUpload,
        ];
    }

    private function startWorker(array $payload): array
    {
        $script = base_path('tests/Support/dispatch_in_progress_worker.php');
        $process = proc_open([PHP_BINARY, $script, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, base_path());
        if (! is_resource($process)) self::fail('Could not start isolated dispatch worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'output' => '', 'errors' => ''];
    }

    private function waitForUploadSignal(array &$worker, string $signalPath): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($signalPath) && microtime(true) < $deadline) {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                $result = $this->finishWorker($worker, 1);
                self::fail('The first request ended before fake archive upload began: '.json_encode($result));
            }
            usleep(10000);
        }
        self::assertFileExists($signalPath, 'The delayed fake archive never signaled its upload.');
    }

    private function finishWorker(array &$worker, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $worker['output'] .= stream_get_contents($worker['stdout']) ?: '';
            $worker['errors'] .= stream_get_contents($worker['stderr']) ?: '';
            $status = proc_get_status($worker['process']);
            if (! $status['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($worker['process']);
                self::fail('An isolated dispatch worker exceeded its timeout.');
            }
            usleep(10000);
        }
        $worker['output'] .= stream_get_contents($worker['stdout']) ?: '';
        $worker['errors'] .= stream_get_contents($worker['stderr']) ?: '';
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        proc_close($worker['process']);
        $result = json_decode(trim($worker['output']), true);
        self::assertIsArray($result, 'Dispatch worker did not return JSON: '.$worker['output'].' '.$worker['errors']);
        return $result;
    }

    private function runWorker(array $payload, int $timeoutSeconds): array
    {
        $worker = $this->startWorker($payload);
        return $this->finishWorker($worker, $timeoutSeconds);
    }

    private function uploadCount(string $controlRoot, string $scenario): int
    {
        $log = $controlRoot.DIRECTORY_SEPARATOR.'uploads.log';
        if (! is_file($log)) return 0;
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return count(array_filter($lines, fn (string $line): bool => $line === $scenario));
    }
}
