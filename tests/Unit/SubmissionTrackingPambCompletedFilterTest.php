<?php

namespace Tests\Unit;

use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\SubmissionTracking\RoutingStatusPresenter;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Runs the production PAMB resolver and Completed predicates using SQLite memory tables only. */
final class SubmissionTrackingPambCompletedFilterTest extends TestCase
{
    private mixed $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private Capsule $database;
    private PambRoutingTimelineService $pambRouting;
    private object $tracking;
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
        $schema->create('conservation_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key')->nullable();
            $table->unsignedBigInteger('protected_area_id')->nullable();
            $table->date('date_report_released_cenro')->nullable();
            $table->date('date_received_penro')->nullable();
            $table->date('date_endorsed_regional')->nullable();
        });
        $schema->create('pamb_routing_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conservation_report_submission_id');
            $table->string('workflow_key');
            $table->string('stage_key');
            $table->dateTime('occurred_at')->nullable();
        });
        $schema->create('engp_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->date('date_received_penro')->nullable();
            $table->date('date_endorsed_regional')->nullable();
            $table->softDeletes();
        });
        $schema->create('document_routing_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('to_stage');
        });

        $this->pambRouting = (new ReflectionClass(PambRoutingTimelineService::class))->newInstanceWithoutConstructor();
        $this->setProperty($this->pambRouting, PambRoutingTimelineService::class, 'routingPolicy', new ProtectedAreaRoutingPolicy);

        $this->tracking = (new ReflectionClass(SubmissionTrackingService::class))->newInstanceWithoutConstructor();
        $this->setProperty($this->tracking, SubmissionTrackingService::class, 'pambRouting', $this->pambRouting);
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

    public function test_pamb_completed_filter_uses_the_specialized_terminal_release_rule(): void
    {
        $this->database->getConnection()->table('conservation_report_submissions')->insert([
            ['id' => 1, 'workflow_key' => 'regular_pamb', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 2, 'workflow_key' => 'regular_pamb', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => null],
            ['id' => 3, 'workflow_key' => 'regular_pamb', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 4, 'workflow_key' => 'special_pamb', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => null],
            ['id' => 5, 'workflow_key' => 'special_pamb', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 6, 'workflow_key' => 'twc_meetings', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 7, 'workflow_key' => 'homestay', 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
        ]);
        $this->database->getConnection()->table('pamb_routing_events')->insert([
            ['conservation_report_submission_id' => 2, 'workflow_key' => 'regular_pamb', 'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL, 'occurred_at' => '2026-09-22 09:00:00'],
            ['conservation_report_submission_id' => 3, 'workflow_key' => 'regular_pamb', 'stage_key' => PambRoutingTimelineService::RECEIVED_BY_TSD, 'occurred_at' => '2026-09-21 09:00:00'],
            ['conservation_report_submission_id' => 4, 'workflow_key' => 'special_pamb', 'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL, 'occurred_at' => '2026-09-21 09:00:00'],
            ['conservation_report_submission_id' => 4, 'workflow_key' => 'special_pamb', 'stage_key' => PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION, 'occurred_at' => '2026-09-22 09:00:00'],
            ['conservation_report_submission_id' => 4, 'workflow_key' => 'special_pamb', 'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL.'__cycle_2', 'occurred_at' => '2026-09-23 09:00:00'],
            ['conservation_report_submission_id' => 5, 'workflow_key' => 'special_pamb', 'stage_key' => PambRoutingTimelineService::RELEASED_TO_REGIONAL, 'occurred_at' => '2026-09-21 09:00:00'],
            ['conservation_report_submission_id' => 5, 'workflow_key' => 'special_pamb', 'stage_key' => PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION, 'occurred_at' => '2026-09-22 09:00:00'],
            ['conservation_report_submission_id' => 5, 'workflow_key' => 'special_pamb', 'stage_key' => PambRoutingTimelineService::RECEIVED_BY_CDS.'__cycle_2', 'occurred_at' => '2026-09-23 09:00:00'],
            ['conservation_report_submission_id' => 6, 'workflow_key' => 'twc_meetings', 'stage_key' => PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL, 'occurred_at' => '2026-09-21 09:00:00'],
        ]);

        $pambRows = ConservationReportSubmission::query()->whereIn('id', [1, 2, 3, 4, 5, 6])->with('routingEvents')->get()->keyBy('id');

        self::assertFalse($this->pambRouting->isComplete($pambRows->get(1), $pambRows->get(1)->routingEvents));
        self::assertTrue($this->pambRouting->isComplete($pambRows->get(2), $pambRows->get(2)->routingEvents));
        self::assertFalse($this->pambRouting->isComplete($pambRows->get(3), $pambRows->get(3)->routingEvents));
        self::assertTrue($this->pambRouting->isComplete($pambRows->get(4), $pambRows->get(4)->routingEvents), 'Special PAMB completes from the terminal event in its active correction cycle.');
        self::assertFalse($this->pambRouting->isComplete($pambRows->get(5), $pambRows->get(5)->routingEvents), 'A terminal event from the prior cycle cannot complete an incomplete active cycle.');
        self::assertTrue($this->pambRouting->isComplete($pambRows->get(6), $pambRows->get(6)->routingEvents), 'The existing final-records-receipt legacy fallback remains permitted.');

        self::assertSame([2, 4, 6, 7], $this->filteredIds(ConservationReportSubmission::query(), 'conservation', 'conservation_report_submissions'));
    }

    public function test_engp_completed_filter_keeps_event_and_no_events_legacy_behavior(): void
    {
        $this->database->getConnection()->table('engp_report_submissions')->insert([
            ['id' => 1, 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 2, 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => '2026-09-22'],
            ['id' => 3, 'date_received_penro' => '2026-09-20', 'date_endorsed_regional' => null],
        ]);
        $this->database->getConnection()->table('document_routing_events')->insert([
            ['source_type' => 'engp', 'source_id' => 2, 'to_stage' => 'penro_records'],
            ['source_type' => 'engp', 'source_id' => 3, 'to_stage' => 'released_to_regional'],
        ]);

        self::assertSame([1, 3], $this->filteredIds(EngpReportSubmission::query(), 'engp', 'engp_report_submissions'));
    }

    private function filteredIds($query, string $sourceKey, string $table): array
    {
        $this->filter->invoke($this->tracking, $query, $sourceKey, RoutingStatusPresenter::COMPLETED, $table, null);

        return $query->orderBy($table.'.id')->pluck($table.'.id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function setProperty(object $target, string $class, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($class, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
