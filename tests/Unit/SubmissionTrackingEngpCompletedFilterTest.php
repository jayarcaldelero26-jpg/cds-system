<?php

namespace Tests\Unit;

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Exercises the production private filter against explicitly created in-memory tables only. */
final class SubmissionTrackingEngpCompletedFilterTest extends TestCase
{
    private mixed $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private Capsule $database;
    private object $service;
    private ReflectionMethod $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();

        $container = new Container;
        Container::setInstance($container);
        Model::unsetEventDispatcher();

        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();

        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('engp_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->date('date_received_penro')->nullable();
            $table->softDeletes();
        });
        $schema->create('document_routing_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('to_stage');
        });
        $schema->create('conservation_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key')->nullable();
            $table->date('date_received_penro')->nullable();
            $table->date('date_endorsed_regional')->nullable();
        });
        $schema->create('pamb_routing_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conservation_report_submission_id');
            $table->string('stage_key');
        });

        $this->service = (new ReflectionClass(SubmissionTrackingService::class))->newInstanceWithoutConstructor();
        $this->filter = new ReflectionMethod(SubmissionTrackingService::class, 'applyStatusFilter');
        $this->filter->setAccessible(true);
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

    public function test_engp_completed_filter_waits_for_released_regional_stage(): void
    {
        $this->database->getConnection()->table('engp_report_submissions')->insert([
            'id' => 1,
            'date_received_penro' => '2026-09-20',
        ]);

        self::assertSame([], $this->filteredIds(EngpReportSubmission::query(), 'engp', 'engp_report_submissions'));

        $this->database->getConnection()->table('document_routing_events')->insert([
            'source_type' => 'engp',
            'source_id' => 1,
            'to_stage' => 'released_to_regional',
        ]);

        self::assertSame([1], $this->filteredIds(EngpReportSubmission::query(), 'engp', 'engp_report_submissions'));
    }

    public function test_engp_completed_filter_requires_a_matching_regional_release_event(): void
    {
        $this->database->getConnection()->table('engp_report_submissions')->insert([
            ['id' => 1, 'date_received_penro' => '2026-09-20'],
            ['id' => 2, 'date_received_penro' => '2026-09-20'],
            ['id' => 3, 'date_received_penro' => '2026-09-20'],
            ['id' => 4, 'date_received_penro' => '2026-09-20'],
        ]);

        $this->database->getConnection()->table('document_routing_events')->insert([
            ['source_type' => 'engp', 'source_id' => 2, 'to_stage' => 'penro_records'],
            ['source_type' => 'conservation', 'source_id' => 3, 'to_stage' => 'released_to_regional'],
            ['source_type' => 'engp', 'source_id' => 4, 'to_stage' => 'released_to_regional'],
        ]);

        self::assertSame([4], $this->filteredIds(EngpReportSubmission::query(), 'engp', 'engp_report_submissions'));
    }

    public function test_conservation_completed_filter_keeps_its_existing_date_predicate(): void
    {
        $this->database->getConnection()->table('conservation_report_submissions')->insert([
            ['id' => 1, 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 2, 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => null],
        ]);

        self::assertSame([1], $this->filteredIds(ConservationReportSubmission::query(), 'conservation', 'conservation_report_submissions'));
    }

    /** @return list<int> */
    private function filteredIds($query, string $sourceKey, string $table): array
    {
        $this->filter->invoke($this->service, $query, $sourceKey, RoutingStatusPresenter::COMPLETED, $table, null);

        return $query->orderBy($table.'.id')->pluck($table.'.id')->map(static fn ($id): int => (int) $id)->all();
    }
}
