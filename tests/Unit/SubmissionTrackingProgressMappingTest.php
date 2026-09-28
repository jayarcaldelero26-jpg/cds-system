<?php

namespace Tests\Unit;

use App\Services\SubmissionTracking\DocumentRoutingPresenter;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambRoutingTimelineService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Exercises the production stage-to-progress maps without booting Laravel. */
final class SubmissionTrackingProgressMappingTest extends TestCase
{
    public function test_shared_active_source_profiles_use_the_approved_progress_boundaries(): void
    {
        $presenter = (new ReflectionClass(DocumentRoutingPresenter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DocumentRoutingPresenter::class, 'processingPercentage');
        $method->setAccessible(true);
        $expectedStages = [
            DocumentRoutingProfileRegistry::PREPARATION => 0,
            DocumentRoutingProfileRegistry::PENRO_ORIGIN => 0,
            DocumentRoutingProfileRegistry::PAMO_ORIGIN => 0,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF => 20,
            DocumentRoutingProfileRegistry::CENRO_CHIEF => 20,
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_RECORDS => 50,
            DocumentRoutingProfileRegistry::CENRO_RECORDS => 50,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS => 80,
            DocumentRoutingProfileRegistry::PENRO_RECORDS => 80,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO => 85,
            DocumentRoutingProfileRegistry::OFFICE_PENRO => 85,
            DocumentRoutingProfileRegistry::TRANSIT_TSD => 88,
            DocumentRoutingProfileRegistry::TSD => 90,
            DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL => 92,
            DocumentRoutingProfileRegistry::CDS_FOCAL => 94,
            DocumentRoutingProfileRegistry::TRANSIT_CDS_CHIEF => 96,
            DocumentRoutingProfileRegistry::CDS_CHIEF => 100,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO_RETURN => 100,
            DocumentRoutingProfileRegistry::OFFICE_PENRO_RETURN => 100,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL => 100,
            DocumentRoutingProfileRegistry::PENRO_RECORDS_FINAL => 100,
            DocumentRoutingProfileRegistry::RELEASED_REGIONAL => 100,
            'unmapped_stage' => 0,
        ];

        foreach (['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans'] as $source) {
            foreach ($expectedStages as $stage => $percentage) {
                self::assertSame($percentage, $method->invoke($presenter, $source, $stage), $source.' '.$stage);
            }
        }
    }

    public function test_aws_reference_stages_remain_twenty_eighty_eighty_and_eighty_five_percent(): void
    {
        $presenter = (new ReflectionClass(DocumentRoutingPresenter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DocumentRoutingPresenter::class, 'processingPercentage');
        foreach ([
            DocumentRoutingProfileRegistry::TRANSIT_CENRO_CHIEF => 20,
            DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS => 80,
            DocumentRoutingProfileRegistry::PENRO_RECORDS => 80,
            DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO => 85,
        ] as $stage => $percentage) {
            self::assertSame($percentage, $method->invoke($presenter, 'aws', $stage), 'AWS '.$stage);
        }
    }

    public function test_regular_special_and_twc_pamb_maps_retain_specialized_stage_keys(): void
    {
        $presenter = (new ReflectionClass(DocumentRoutingPresenter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DocumentRoutingPresenter::class, 'pambProcessingPercentage');
        $method->setAccessible(true);
        $expectedStages = [
            SubmissionTrackingService::CENRO_RELEASE => 0,
            PambRoutingTimelineService::RECORDS_RECEIVED => 80,
            PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO => 85,
            PambRoutingTimelineService::RECEIVED_BY_PENRO => 85,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD => 88,
            PambRoutingTimelineService::RECEIVED_BY_TSD => 90,
            PambRoutingTimelineService::FORWARDED_TSD_TO_CDS => 92,
            PambRoutingTimelineService::RECEIVED_BY_CDS => 94,
            PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF => 96,
            PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF => 100,
            PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO => 100,
            PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL => 100,
            PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION => 100,
            PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL => 100,
            PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS => 100,
            PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL => 100,
            PambRoutingTimelineService::RELEASED_TO_REGIONAL => 100,
            'unmapped_stage' => 0,
        ];

        foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
            foreach ($expectedStages as $stage => $percentage) {
                self::assertSame($percentage, $method->invoke($presenter, $stage), $workflow.' '.$stage);
            }
        }
    }

    public function test_every_active_module_key_resolves_to_a_registered_tracking_source_and_supported_profile(): void
    {
        $expectedConservation = [
            'homestay', 'regular_pamb', 'special_pamb', 'maintenance_monuments', 'maintenance_buoy', 'twc_meetings',
            'updating_pamp', 'restoration_plan_5_year', 'additional_bms_site', 'cepa_plan', 'vtol_operations',
            'bdfe_terrestrial', 'bdfap', 'maintenance_pamo_ecotourism', 'rehabilitation_pa_office',
            'ecotourism_management_plan', 'updating_pamb_manual', 'management_effectiveness_assessment',
            'maintenance_pa_information_system', 'monitoring_mangroves_corals_seagrass', 'water_quality_monitoring', 'mpan',
        ];
        $expectedEngp = [
            'cbep', 'elcac', 'ngp_staff_accomplishment', 'forest_disturbance', 'monthly_accomplishment_pmd_fmb',
            'cenro_nursery_seedling', 'tree_replacement', 'rims', 'ngp_produce', 'nursery_maintenance', 'site_visit',
            'weekly_accomplishment',
        ];
        $conservationKeys = (new ConservationReportWorkflowRegistry)->defaultKeys();
        $engpKeys = (new EngpReportWorkflowRegistry)->defaultKeys();
        sort($expectedConservation);
        sort($expectedEngp);
        $actualConservation = $conservationKeys;
        $actualEngp = $engpKeys;
        sort($actualConservation);
        sort($actualEngp);
        self::assertSame($expectedConservation, $actualConservation, 'All 22 active Conservation workflow keys are included.');
        self::assertSame($expectedEngp, $actualEngp, 'All 12 active ENGP workflow keys are included.');

        $modules = [];
        foreach ($conservationKeys as $key) {
            $modules[$key] = [
                'source' => 'conservation',
                'profile' => in_array($key, ['regular_pamb', 'special_pamb', 'twc_meetings'], true) ? 'pamb_detailed' : 'canonical',
            ];
        }
        foreach ($engpKeys as $key) {
            $modules['engp_'.$key] = ['source' => 'engp', 'profile' => 'canonical'];
        }
        foreach ([
            'bms' => 'bms',
            'bams' => 'bams',
            'imea' => 'imea',
            'imea_facility_maintenance' => 'imea-maintenance',
            'automated_weather_station' => 'aws',
            'ipaf_management' => 'ipaf-management',
            'revenue_collection' => 'revenue',
            'management_plans' => 'management-plans',
        ] as $module => $source) {
            $modules[$module] = ['source' => $source, 'profile' => 'canonical'];
        }

        self::assertCount(42, $modules, 'The active module definition inventory must resolve all 42 module keys.');
        self::assertSame(22, count((new ConservationReportWorkflowRegistry)->defaultKeys()));
        self::assertSame(12, count((new EngpReportWorkflowRegistry)->defaultKeys()));
        self::assertSame(3, count(array_filter($modules, fn (array $item): bool => $item['profile'] === 'pamb_detailed')));

        $service = (new ReflectionClass(SubmissionTrackingService::class))->newInstanceWithoutConstructor();
        $sourceMethod = new ReflectionMethod(SubmissionTrackingService::class, 'sources');
        $sources = $sourceMethod->invoke($service);
        self::assertCount(10, $sources, 'All ten active Submission Tracking source families are registered.');
        self::assertCount(10, array_unique(array_column($modules, 'source')));
        foreach ($modules as $module => $resolution) {
            self::assertArrayHasKey($resolution['source'], $sources, $module.' resolves to a registered source family');
            self::assertContains($resolution['profile'], ['canonical', 'pamb_detailed'], $module.' resolves to a supported shared profile');
        }
    }

    public function test_all_pamb_workflows_map_mov_statuses_to_shared_overall_progress(): void
    {
        $presenter = (new ReflectionClass(DocumentRoutingPresenter::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DocumentRoutingPresenter::class, 'pambMovProcessingPercentage');
        $expected = [
            PambMovProcessingService::ACTIVITY_CONDUCTED => 0,
            PambMovProcessingService::MOV_UPLOADED => 0,
            PambMovProcessingService::SUBMITTED_FOR_REVIEW => 20,
            PambMovProcessingService::RESUBMITTED_FOR_REVIEW => 20,
            PambMovProcessingService::NEEDS_CORRECTION => 0,
            PambMovProcessingService::READY_FOR_RELEASE => 50,
            PambMovProcessingService::RELEASED_BY_CENRO => 80,
        ];

        foreach (['regular_pamb', 'special_pamb', 'twc_meetings'] as $workflow) {
            foreach ($expected as $status => $percentage) {
                self::assertSame($percentage, $method->invoke($presenter, $status, false), $workflow.' '.$status);
            }
            foreach ([
                PambMovProcessingService::SUBMITTED_FOR_REVIEW,
                PambMovProcessingService::RESUBMITTED_FOR_REVIEW,
                PambMovProcessingService::NEEDS_CORRECTION,
                PambMovProcessingService::READY_FOR_RELEASE,
                PambMovProcessingService::RECEIVED_BY_PENRO,
            ] as $status) {
                self::assertSame(80, $method->invoke($presenter, $status, true), $workflow.' direct-PENRO '.$status);
            }
        }
    }
}
