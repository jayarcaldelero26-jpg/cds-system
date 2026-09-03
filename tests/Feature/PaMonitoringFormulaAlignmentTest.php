<?php

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ModuleDefinition;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Modules\ModuleDeadlineService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
});

test('standard working days are distinct from calendar days at a weekend boundary', function (): void {
    $calendar = app(BusinessCalendarService::class);

    expect($calendar->addWorkingDays('2026-08-28', 7, null, BusinessCalendarService::STANDARD_WORKING_WEEKDAYS)->toDateString())->toBe('2026-09-08')
        ->and(CarbonImmutable::parse('2026-08-28')->addDays(7)->toDateString())->toBe('2026-09-04');
});

test('the authoritative registry exposes explicit deadline modes', function (): void {
    $registry = app(ConservationReportWorkflowRegistry::class);

    expect($registry->deadlineRule('regular_pamb', 'Regular PAMB', 'Minutes'))->toMatchArray([
        'deadline_mode' => ModuleDefinition::DEADLINE_PAMB_WORKING_DAYS,
        'deadline_days' => 7,
    ])
        ->and($registry->deadlineRule('additional_bms_site', null, null))->toMatchArray([
            'deadline_mode' => ModuleDefinition::DEADLINE_CALENDAR_DAYS,
            'deadline_days' => 15,
        ])
        ->and($registry->deadlineRule('ecotourism_management_plan', null, null))->toMatchArray([
            'deadline_mode' => ModuleDefinition::DEADLINE_CALENDAR_DAYS,
            'deadline_days' => 7,
        ])
        ->and($registry->find('inland_wetland'))->toBeNull();
});

test('specialized PA monitoring models use their authoritative standard working-day rules', function (): void {
    $cases = [
        [Aws::class, 7, '2026-09-08'],
        [BmsReportSubmission::class, 15, '2026-09-18'],
        [BamsReportSubmission::class, 15, '2026-09-18'],
        [ImeaReportSubmission::class, 15, '2026-09-18'],
        [ImeaFacilityMaintenanceReport::class, 7, '2026-09-08'],
        [IpafManagementReport::class, 7, '2026-09-08'],
    ];

    foreach ($cases as [$modelClass, $days, $expected]) {
        $model = new $modelClass(['date_accomplished' => '2026-08-28']);

        expect($model->deadline_submission)->toBe($expected)
            ->and($model->deadline_submission)->not->toBe(CarbonImmutable::parse('2026-08-28')->addDays($days)->toDateString());
    }
});

test('generic PA monitoring workflows use their authoritative calendar modes', function (): void {
    $additional = new ConservationReportSubmission([
        'workflow_key' => 'additional_bms_site',
        'activity_name' => 'Establishment of additional BMS site (Davao de Oro)',
        'document_type' => 'Progress Report',
        'date_accomplished' => '2026-08-28',
    ]);
    $eco = new ConservationReportSubmission([
        'workflow_key' => 'ecotourism_management_plan',
        'activity_name' => 'Final Plan',
        'document_type' => 'Final Report',
        'date_accomplished' => '2026-08-28',
    ]);
    $updating = new ConservationReportSubmission([
        'workflow_key' => 'updating_pamp',
        'activity_name' => 'Updating of PAMP',
        'document_type' => 'Progress Report',
        'date_accomplished' => '2026-08-28',
    ]);

    expect($additional->deadline_submission)->toBe('2026-09-12')
        ->and($eco->deadline_submission)->toBe('2026-09-04')
        ->and($updating->deadline_submission)->toBe('2026-09-08');
});

test('management plan deadlines are type-specific', function (): void {
    $updating = new ManagementPlan(['plan_type' => 'Updating of PAMP', 'date_accomplished' => '2026-08-28']);
    $cepaProgress = new ManagementPlan(['plan_type' => 'CEPA', 'document_type' => 'Progress Report', 'date_accomplished' => '2026-08-28']);
    $cepaFinal = new ManagementPlan(['plan_type' => 'CEPA', 'document_type' => 'Final Report', 'date_accomplished' => '2026-08-28']);
    $restoration = new ManagementPlan(['plan_type' => '5-Year Restoration Plan', 'date_accomplished' => '2026-08-28']);
    $ecotourism = new ManagementPlan(['plan_type' => 'Ecotourism Management Plan', 'date_accomplished' => '2026-08-28']);

    expect($updating->deadline_submission)->toBe('2026-09-08')
        ->and($cepaProgress->deadline_submission)->toBe('2026-09-08')
        ->and($cepaFinal->deadline_submission)->toBe('2026-09-18')
        ->and($restoration->deadline_submission)->toBe('2026-09-08')
        ->and($ecotourism->deadline_submission)->toBe('2026-09-04');
});

test('module deadline service supports standard, calendar, and stored custom modes', function (): void {
    $service = app(ModuleDeadlineService::class);
    $standard = new ModuleDefinition(['deadline_mode' => ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 'default_deadline_days' => 7, 'allow_deadline_override' => false]);
    $calendar = new ModuleDefinition(['deadline_mode' => ModuleDefinition::DEADLINE_CALENDAR_DAYS, 'default_deadline_days' => 7, 'allow_deadline_override' => false]);
    $stored = new ModuleDefinition(['deadline_mode' => ModuleDefinition::DEADLINE_CUSTOM_STORED, 'default_deadline_days' => null, 'allow_deadline_override' => true]);

    expect($service->resolve($standard, '2026-08-28')['deadline_date'])->toBe('2026-09-08')
        ->and($service->resolve($calendar, '2026-08-28')['deadline_date'])->toBe('2026-09-04')
        ->and($service->resolve($stored, '2026-08-28', '2026-09-18')['deadline_date'])->toBe('2026-09-18');
});

test('Revenue Collection preserves the explicit stored deadline and does not use generic arithmetic', function (): void {
    $revenue = new IpafRevenueCollection(['deadline_submission' => '2026-09-18']);

    expect($revenue->deadline_submission->toDateString())->toBe('2026-09-18')
        ->and($revenue->deadline_submission->toDateString())->not->toBe('2026-09-25');
});
