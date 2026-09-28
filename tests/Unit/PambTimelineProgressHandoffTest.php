<?php

namespace Tests\Unit;

use App\Models\ConservationReportSubmission;
use App\Models\PambRoutingEvent;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService as Timeline;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** Actual timeline -> presenter; no Laravel boot, migrations, files, or provider calls. */
final class PambTimelineProgressHandoffTest extends TestCase
{
    private Capsule $database;
    private mixed $previousContainer;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private Timeline $timeline;
    private DocumentRoutingPresenter $presenter;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $container = new Container;
        Container::setInstance($container);
        Model::unsetEventDispatcher();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->getSchemaBuilder()->create('submission_routing_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('source_record_id');
            $table->string('action_key');
        });

        $policy = new ProtectedAreaRoutingPolicy;
        $organization = new OrganizationalAccessService;
        // Calendar arithmetic is outside this contract; prevent holiday queries.
        $calendar = $this->createMock(BusinessCalendarService::class);
        $calendar->method('workingDaysBetween')->willReturn(0);
        $this->timeline = (new ReflectionClass(Timeline::class))->newInstanceWithoutConstructor();
        $this->presenter = (new ReflectionClass(DocumentRoutingPresenter::class))->newInstanceWithoutConstructor();
        foreach ([$this->timeline, $this->presenter] as $service) {
            foreach (['routingPolicy' => $policy, 'organization' => $organization, 'calendar' => $calendar] as $name => $dependency) {
                (new ReflectionProperty($service, $name))->setValue($service, $dependency);
            }
        }
        $container->instance(Timeline::class, $this->timeline);
        $mov = (new ReflectionClass(PambMovProcessingService::class))->newInstanceWithoutConstructor();
        foreach (['routingPolicy' => $policy, 'compliance' => new PambComplianceCalculator($calendar, $policy)] as $name => $dependency) {
            (new ReflectionProperty($mov, $name))->setValue($mov, $dependency);
        }
        $container->instance(PambMovProcessingService::class, $mov);
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        $this->previousResolver ? Model::setConnectionResolver($this->previousResolver) : Model::unsetConnectionResolver();
        $this->previousDispatcher ? Model::setEventDispatcher($this->previousDispatcher) : Model::unsetEventDispatcher();
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_actual_terminal_and_correction_cycle_handoff_for_each_pamb_workflow(): void
    {
        foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
            $report = $this->report($workflow, [Timeline::RELEASED_TO_REGIONAL]);
            [$timeline, $routing] = $this->present($report);
            self::assertTrue($timeline['routing_complete'], $workflow);
            self::assertNull($routing['current_stage'], $workflow);
            self::assertSame(100, $routing['processing_percentage'], $workflow);

            $report = $this->report($workflow, [Timeline::RELEASED_TO_REGIONAL, Timeline::PENRO_FINAL_RETURNED_FOR_CORRECTION]);
            $report->mov_processing_status = PambMovProcessingService::READY_FOR_RELEASE;
            [$timeline, $routing] = $this->present($report);
            self::assertFalse($timeline['routing_complete'], $workflow);
            self::assertSame(Timeline::RECEIVED_BY_CDS.'__cycle_2', $routing['current_stage'], $workflow);
            self::assertSame(94, $routing['processing_percentage'], $workflow);

            $report = $this->report($workflow, [Timeline::RELEASED_TO_REGIONAL, Timeline::PENRO_FINAL_RETURNED_FOR_CORRECTION, Timeline::RELEASED_TO_REGIONAL.'__cycle_2']);
            // Completed production transitions retain this source milestone.
            $report->date_endorsed_regional = '2026-09-23';
            [$timeline, $routing] = $this->present($report);
            self::assertTrue($timeline['routing_complete'], $workflow);
            self::assertNull($routing['current_stage'], $workflow);
            self::assertSame(100, $routing['processing_percentage'], $workflow);
        }
    }

    public function test_pending_forward_remains_at_sender_until_the_forward_event_is_recorded(): void
    {
        foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
            [$timeline, $routing] = $this->present($this->report($workflow, []));
            self::assertSame(Timeline::FORWARDED_RECORDS_TO_PENRO, $routing['current_stage']);
            self::assertFalse($timeline['routing_complete']);
            self::assertSame(85, $routing['processing_percentage']);
            self::assertContains('penro_receipt', $routing['business_date_actions']);
            self::assertNotContains(Timeline::RECEIVED_BY_PENRO, $routing['business_date_actions']);

            [$timeline, $routing] = $this->present($this->report($workflow, [Timeline::FORWARDED_RECORDS_TO_PENRO]));
            self::assertSame(Timeline::RECEIVED_BY_PENRO, $routing['current_stage']);
            self::assertSame(85, $routing['processing_percentage']);
            self::assertFalse($timeline['routing_complete']);
        }
    }

    public function test_pre_release_mov_status_drives_overall_progress_in_regular_special_and_twc_workflows(): void
    {
        $expected = [
            PambMovProcessingService::ACTIVITY_CONDUCTED => 0,
            PambMovProcessingService::MOV_UPLOADED => 0,
            PambMovProcessingService::SUBMITTED_FOR_REVIEW => 20,
            PambMovProcessingService::NEEDS_CORRECTION => 0,
            PambMovProcessingService::READY_FOR_RELEASE => 50,
        ];

        foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
            foreach ($expected as $status => $percentage) {
                $report = $this->report($workflow, [], released: false);
                $report->forceFill(['mov_processing_status' => $status, 'mov_file_path' => 'private/uat-mov.pdf']);
                [$timeline, $routing] = $this->present($report);

                self::assertSame(SubmissionTrackingService::CENRO_RELEASE, $routing['current_stage'], $workflow.' '.$status);
                self::assertSame($percentage, $routing['processing_percentage'], $workflow.' '.$status);
                self::assertFalse($timeline['routing_complete'], $workflow.' '.$status);
                if ($status === PambMovProcessingService::ACTIVITY_CONDUCTED) {
                    self::assertSame('Submit MOV/report for CENRO CDS Chief review', $routing['next_expected_action']);
                }
            }
        }
    }

    public function test_penro_cds_chief_can_be_at_one_hundred_percent_without_regional_routing_completion(): void
    {
        $report = $this->report('regular_pamb', [], released: false);
        $current = Timeline::RECEIVED_BY_CDS_CHIEF;
        $routing = $this->presenter->presentPamb($report, [
            'routing_complete' => false,
            'routing_summary' => [],
            'timeline' => [['key' => $current, 'stage_key' => $current, 'status' => 'current']],
        ]);

        self::assertSame(100, $routing['processing_percentage']);
        self::assertFalse($this->timeline->present($report, CarbonImmutable::parse('2026-09-28'), [], collect())['routing_complete']);
    }

    private function report(string $workflow, array $keys, bool $released = true): ConservationReportSubmission
    {
        $report = new ConservationReportSubmission;
        $report->forceFill(['id' => 1, 'workflow_key' => $workflow, 'target_office' => 'CENRO Mati', 'date_report_released_cenro' => $released ? '2026-09-20' : null, 'date_received_penro' => $released ? '2026-09-20' : null]);
        $events = [];
        foreach ($keys as $index => $key) {
            $event = new PambRoutingEvent;
            $event->forceFill(['id' => $index + 1, 'stage_key' => $key, 'occurred_at' => '2026-09-'.(21 + $index).' 09:00:00']);
            $event->setRelation('recordedBy', null);
            $events[] = $event;
        }
        $report->setRelation('routingEvents', new Collection($events));
        return $report;
    }

    private function present(ConservationReportSubmission $report): array
    {
        $timeline = $this->timeline->present($report, CarbonImmutable::parse('2026-09-28'), [], collect());
        return [$timeline, $this->presenter->presentPamb($report, $timeline)];
    }
}
