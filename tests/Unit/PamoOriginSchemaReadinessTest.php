<?php

namespace Tests\Unit;

use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\SubmissionRoutingSnapshot;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Pamo\PamoOriginSchemaReadiness;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Exercises optional-origin compatibility against isolated in-memory SQLite only. */
final class PamoOriginSchemaReadinessTest extends TestCase
{
    private mixed $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();

        $container = new Container;
        Container::setInstance($container);
        $request = Request::create('/');
        $container->instance('request', $request);
        $container->instance(Request::class, $request);
        Model::unsetEventDispatcher();

        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $this->database->getConnection()->enableQueryLog();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_application_provider_resolves_origin_services_in_request_scope_without_database_queries(): void
    {
        $container = Container::getInstance();
        (new AppServiceProvider($container))->register();

        $readiness = $container->make(PamoOriginSchemaReadiness::class);
        $snapshots = $container->make(RoutingPositionSnapshotService::class);
        $presenter = $container->make(RoutingStatusPresenter::class);

        self::assertSame($snapshots, $container->make(RoutingPositionSnapshotService::class));
        self::assertSame($readiness, (new ReflectionProperty($snapshots, 'pamoOrigins'))->getValue($snapshots));
        self::assertSame($readiness, (new ReflectionProperty($presenter, 'pamoOrigins'))->getValue($presenter));
        $genericRouting = (new ReflectionProperty($presenter, 'genericRouting'))->getValue($presenter);
        self::assertSame($snapshots, (new ReflectionProperty($genericRouting, 'positionSnapshots'))->getValue($genericRouting));
        self::assertSame([], $this->database->getConnection()->getQueryLog());

        $container->forgetScopedInstances();

        $nextReadiness = $container->make(PamoOriginSchemaReadiness::class);
        $nextSnapshots = $container->make(RoutingPositionSnapshotService::class);
        $nextPresenter = $container->make(RoutingStatusPresenter::class);

        self::assertNotSame($readiness, $nextReadiness);
        self::assertNotSame($snapshots, $nextSnapshots);
        self::assertSame($nextReadiness, (new ReflectionProperty($nextSnapshots, 'pamoOrigins'))->getValue($nextSnapshots));
        self::assertSame($nextReadiness, (new ReflectionProperty($nextPresenter, 'pamoOrigins'))->getValue($nextPresenter));
        $nextGenericRouting = (new ReflectionProperty($nextPresenter, 'genericRouting'))->getValue($nextPresenter);
        self::assertSame($nextSnapshots, (new ReflectionProperty($nextGenericRouting, 'positionSnapshots'))->getValue($nextGenericRouting));
        self::assertSame([], $this->database->getConnection()->getQueryLog());
    }

    public function test_missing_snapshot_table_uses_legacy_presentation_without_origin_select(): void
    {
        $this->database->getConnection()->flushQueryLog();

        self::assertSame(
            RoutingStatusPresenter::PENDING_PENRO,
            $this->presenter()->status($this->conservationRecord(), 'conservation', collect(), 'direct'),
        );
        self::assertFalse($this->selectedOriginKind());
    }

    public function test_snapshot_table_without_origin_kind_uses_legacy_presentation_without_origin_select(): void
    {
        $this->createSnapshotTable(false);
        $this->database->getConnection()->flushQueryLog();

        self::assertSame(
            RoutingStatusPresenter::PENDING_PENRO,
            $this->presenter()->status($this->conservationRecord(), 'conservation', collect(), 'direct'),
        );
        self::assertFalse($this->selectedOriginKind());
    }

    public function test_installed_schema_preserves_legacy_snapshot_presentation(): void
    {
        $this->createSnapshotTable(true);
        $this->insertSnapshot(39, null, 'regular', RoutingPositionSnapshotService::GRAPH_VERSION);
        $this->database->getConnection()->flushQueryLog();

        self::assertSame(
            RoutingStatusPresenter::PENDING_PENRO,
            $this->presenter()->status($this->conservationRecord(39), 'conservation', collect(), 'direct'),
        );
        self::assertTrue($this->selectedOriginKind());
    }

    public function test_partial_origin_schema_fails_closed_instead_of_downgrading_presentation(): void
    {
        $this->database->getConnection()->getSchemaBuilder()->create('submission_routing_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key');
            $table->unsignedBigInteger('source_id');
            $table->string('profile');
            $table->string('graph_version');
            $table->string('origin_kind')->nullable();
        });

        try {
            $this->presenter()->status($this->conservationRecord(), 'conservation', collect(), 'direct');
            self::fail('Expected incomplete origin schema to fail closed.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertFalse($this->selectedOriginKind());
    }

    public function test_complete_installed_schema_recognizes_a_captured_pamo_origin(): void
    {
        $this->createSnapshotTable(true);
        $this->insertSnapshot(39, 'pamo_pamb', 'pamo_direct', RoutingPositionSnapshotService::PAMO_GRAPH_VERSION);

        self::assertTrue((new PamoOriginSchemaReadiness)->isPamoConservationOrigin($this->conservationRecord(39), 'conservation'));
        self::assertFalse((new PamoOriginSchemaReadiness)->isPamoConservationOrigin($this->conservationRecord(39), 'bms'));
    }

    public function test_schema_introspection_is_cached_for_the_request_and_connection(): void
    {
        $this->createSnapshotTable(true);
        $this->insertSnapshot(39, null, 'regular', RoutingPositionSnapshotService::GRAPH_VERSION);
        $this->insertSnapshot(40, null, 'regular', RoutingPositionSnapshotService::GRAPH_VERSION);
        $this->database->getConnection()->flushQueryLog();
        $readiness = new PamoOriginSchemaReadiness;

        self::assertFalse($readiness->isPamoConservationOrigin($this->conservationRecord(39), 'conservation'));
        self::assertFalse($readiness->isPamoConservationOrigin($this->conservationRecord(40), 'conservation'));

        $columnListings = array_filter(
            $this->database->getConnection()->getQueryLog(),
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'pragma_table_xinfo'),
        );
        self::assertCount(1, $columnListings);
    }

    public function test_non_conservation_status_keeps_its_existing_path_without_origin_schema_access(): void
    {
        $this->database->getConnection()->flushQueryLog();
        $record = new BmsReportSubmission;
        $record->setRawAttributes(['id' => 12, 'date_accomplished' => '2026-10-01'], true);

        self::assertSame(
            RoutingStatusPresenter::PENDING_PENRO,
            $this->presenter()->status($record, 'bms', collect(), 'direct'),
        );
        self::assertSame([], $this->database->getConnection()->getQueryLog());
    }

    public function test_regular_route_capture_omits_optional_origin_fields_on_legacy_schema(): void
    {
        $this->createSnapshotTable(false);
        $record = $this->conservationRecord(39);
        $actor = new User;
        $actor->setAttribute('id', 7);

        $snapshot = (new RoutingPositionSnapshotService(new PamoOriginSchemaReadiness))->capture(
            $record,
            'conservation',
            ['needs_capture' => true, 'setting_version_id' => 4],
            'regular',
            $actor,
        );

        self::assertInstanceOf(SubmissionRoutingSnapshot::class, $snapshot);
        self::assertSame(RoutingPositionSnapshotService::GRAPH_VERSION, $snapshot->graph_version);
        self::assertSame(1, $this->database->getConnection()->table('submission_routing_snapshots')->count());
    }

    public function test_pamo_route_capture_fails_closed_when_origin_schema_is_absent(): void
    {
        $this->createSnapshotTable(false);
        $actor = new User;
        $actor->setAttribute('id', 7);

        try {
            (new RoutingPositionSnapshotService(new PamoOriginSchemaReadiness))->capture(
                $this->conservationRecord(39),
                'conservation',
                ['needs_capture' => true, 'setting_version_id' => 4],
                'pamo_direct',
                $actor,
                ['protected_area_id' => 1, 'pamo_office_id' => 2, 'origin_started_at' => '2026-10-01 10:00:00'],
            );
            self::fail('Expected PAMO route capture to fail closed without the origin schema.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertSame(0, $this->database->getConnection()->table('submission_routing_snapshots')->count());
    }

    private function createSnapshotTable(bool $withOriginColumns): void
    {
        $this->database->getConnection()->getSchemaBuilder()->create('submission_routing_snapshots', function (Blueprint $table) use ($withOriginColumns): void {
            $table->id();
            $table->string('source_key');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('setting_version_id')->nullable();
            $table->string('profile');
            $table->string('graph_version');
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->dateTime('captured_at')->nullable();
            if ($withOriginColumns) {
                $table->string('origin_kind')->nullable();
                $table->unsignedBigInteger('protected_area_id')->nullable();
                $table->unsignedBigInteger('pamo_office_id')->nullable();
                $table->string('pamo_office_name')->nullable();
                $table->unsignedBigInteger('responsible_cenro_office_id')->nullable();
                $table->string('responsible_cenro_name')->nullable();
                $table->unsignedBigInteger('originator_user_id')->nullable();
                $table->dateTime('origin_started_at')->nullable();
            }
            $table->timestamps();
        });
    }

    private function insertSnapshot(int $sourceId, ?string $originKind, string $profile, string $graphVersion): void
    {
        $this->database->getConnection()->table('submission_routing_snapshots')->insert([
            'source_key' => 'conservation', 'source_id' => $sourceId, 'setting_version_id' => 4, 'profile' => $profile,
            'graph_version' => $graphVersion, 'captured_by' => 7, 'captured_at' => '2026-10-01 10:00:00', 'origin_kind' => $originKind,
            'protected_area_id' => $originKind === 'pamo_pamb' ? 1 : null,
            'pamo_office_id' => $originKind === 'pamo_pamb' ? 2 : null,
            'pamo_office_name' => $originKind === 'pamo_pamb' ? 'PAMO Fixture' : null,
            'originator_user_id' => $originKind === 'pamo_pamb' ? 7 : null,
            'origin_started_at' => $originKind === 'pamo_pamb' ? '2026-10-01 10:00:00' : null,
        ]);
    }

    private function conservationRecord(int $id = 39): ConservationReportSubmission
    {
        $record = new ConservationReportSubmission;
        $record->setRawAttributes([
            'id' => $id, 'workflow_key' => 'other', 'date_accomplished' => '2026-10-01',
            'date_report_released_cenro' => null, 'date_received_penro' => null, 'date_endorsed_regional' => null,
        ], true);

        return $record;
    }

    private function presenter(): RoutingStatusPresenter
    {
        $presenter = (new ReflectionClass(RoutingStatusPresenter::class))->newInstanceWithoutConstructor();
        $compliance = (new ReflectionClass(PambComplianceCalculator::class))->newInstanceWithoutConstructor();
        $this->setProperty($presenter, 'pambCompliance', $compliance);
        $this->setProperty($presenter, 'pamoOrigins', new PamoOriginSchemaReadiness);
        $this->setProperty($presenter, 'engpWorkflows', new EngpReportWorkflowRegistry);
        $this->setProperty($presenter, 'routingPolicy', new ProtectedAreaRoutingPolicy);
        $this->setProperty($presenter, 'genericRouting', (new ReflectionClass(DocumentRoutingTransitionService::class))->newInstanceWithoutConstructor());

        return $presenter;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setValue($target, $value);
    }

    private function selectedOriginKind(): bool
    {
        foreach ($this->database->getConnection()->getQueryLog() as $query) {
            if (preg_match('/^\s*select\s+.*origin_kind/i', $query['query'])) return true;
        }

        return false;
    }
}
