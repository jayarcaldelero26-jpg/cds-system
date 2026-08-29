<?php

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\NonWorkingDay;
use App\Models\TechnicalReport;
use App\Services\BusinessCalendarService;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    BusinessCalendarService::forgetCache();
});

test('the business calendar treats Monday through Thursday as working and Friday through Sunday as non-working', function () {
    $calendar = app(BusinessCalendarService::class);

    expect($calendar->isWorkingDay('2026-08-24'))->toBeTrue() // Monday
        ->and($calendar->isWorkingDay('2026-08-25'))->toBeTrue()
        ->and($calendar->isWorkingDay('2026-08-26'))->toBeTrue()
        ->and($calendar->isWorkingDay('2026-08-27'))->toBeTrue()
        ->and($calendar->isWorkingDay('2026-08-28'))->toBeFalse() // Friday
        ->and($calendar->isWorkingDay('2026-08-29'))->toBeFalse()
        ->and($calendar->isWorkingDay('2026-08-30'))->toBeFalse();
});

test('active holidays are skipped while inactive holidays remain working dates', function () {
    $calendar = app(BusinessCalendarService::class);

    NonWorkingDay::create([
        'date' => '2026-08-25', 'name' => 'Registered local holiday', 'type' => NonWorkingDay::TYPE_LOCAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_DAVAO_ORIENTAL, 'is_active' => true,
    ]);
    expect($calendar->isWorkingDay('2026-08-25'))->toBeFalse();

    $inactive = NonWorkingDay::create([
        'date' => '2026-08-26', 'name' => 'Inactive holiday', 'type' => NonWorkingDay::TYPE_SPECIAL_NON_WORKING_DAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => false,
    ]);
    expect($calendar->isWorkingDay('2026-08-26'))->toBeTrue();

    $inactive->update(['is_active' => true]);
    expect($calendar->isWorkingDay('2026-08-26'))->toBeFalse();

    NonWorkingDay::create([
        'date' => '2026-08-28', 'name' => 'Friday declaration', 'type' => NonWorkingDay::TYPE_OFFICE_DECLARED_NON_WORKING_DAY,
        'scope' => NonWorkingDay::SCOPE_OFFICE, 'location' => 'CENRO', 'is_active' => true,
    ]);
    expect($calendar->isWorkingDay('2026-08-28', 'CENRO'))->toBeFalse()
        ->and($calendar->isWorkingDay('2026-08-27', 'Other Office'))->toBeTrue();
});

test('duplicate calendar entries for the same date and scope are rejected', function () {
    $attributes = [
        'date' => '2026-08-25', 'name' => 'Holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ];
    NonWorkingDay::create($attributes);

    expect(fn () => NonWorkingDay::create($attributes))->toThrow(QueryException::class);
});

test('addWorkingDays excludes the start date, Friday, weekends, and active holidays', function () {
    $calendar = app(BusinessCalendarService::class);

    expect($calendar->addWorkingDays('2026-08-24', 7)->toDateString())->toBe('2026-09-03');

    NonWorkingDay::create([
        'date' => '2026-08-25', 'name' => 'Holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);
    expect($calendar->addWorkingDays('2026-08-24', 7)->toDateString())->toBe('2026-09-07');
});

test('the same configured holiday is used by Standard A, Standard B, and days complied', function () {
    $calendar = app(BusinessCalendarService::class);
    NonWorkingDay::create([
        'date' => '2026-08-05', 'name' => 'Wednesday holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);

    $standardA = new BmsReportSubmission([
        'date_accomplished' => '2026-08-03', 'date_received_penro' => '2026-08-31', 'target_office' => 'CENRO Mati',
    ]);
    $standardB = new TechnicalReport([
        'date_accomplished' => '2026-08-03', 'submission_date' => '2026-08-17', 'target_office' => 'CENRO Mati',
    ]);

    expect($standardA->deadline_submission)->toBe('2026-08-31')
        ->and($standardA->number_days_complied)->toBe(15)
        ->and($standardB->deadline_submission)->toBe('2026-08-17')
        ->and($standardB->number_days_complied)->toBe(7)
        ->and($calendar->workingDaysBetween('2026-08-03', '2026-08-31'))->toBe(15);
});

test('a configured holiday on a standard weekend remains one non-working date', function () {
    $calendar = app(BusinessCalendarService::class);
    NonWorkingDay::create([
        'date' => '2026-08-28', 'name' => 'Friday holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);

    expect($calendar->isWorkingDay('2026-08-28'))->toBeFalse()
        ->and($calendar->workingDaysBetween('2026-08-24', '2026-09-03'))->toBe(7)
        ->and($calendar->addWorkingDays('2026-08-24', 7)->toDateString())->toBe('2026-09-03');
});

test('working days and signed differences preserve tracker boundaries and signs', function () {
    $calendar = app(BusinessCalendarService::class);

    expect($calendar->workingDaysBetween('2026-07-20', '2026-07-27'))->toBe(4)
        ->and($calendar->workingDaysBetween('2026-07-20', '2026-07-20'))->toBe(0)
        ->and($calendar->signedWorkingDayDifference('2026-07-20', '2026-07-27'))->toBe(-4)
        ->and($calendar->signedWorkingDayDifference('2026-07-20', '2026-07-20'))->toBe(0)
        ->and($calendar->signedWorkingDayDifference('2026-07-20', '2026-07-17'))->toBe(1)
        ->and($calendar->signedWorkingDayDifference('2026-07-20', '2026-07-21'))->toBe(-1);
});

test('all enrolled tracker models consume the centralized Monday-Thursday deadlines and distances', function () {
    $standardA = [BmsReportSubmission::class, BamsReportSubmission::class, ImeaReportSubmission::class];
    foreach ($standardA as $modelClass) {
        $model = new $modelClass(['date_accomplished' => '2026-08-24', 'date_received_penro' => '2026-09-17', 'target_office' => 'CENRO']);
        expect($model->deadline_submission)->toBe('2026-09-17')
            ->and($model->number_days_complied)->toBe(15);
    }

    $standardB = [TechnicalReport::class, Aws::class, ManagementPlan::class, ImeaFacilityMaintenanceReport::class, IpafManagementReport::class];
    foreach ($standardB as $modelClass) {
        $submission = $modelClass === TechnicalReport::class ? ['submission_date' => '2026-09-03'] : ['date_received_penro' => '2026-09-03'];
        $model = new $modelClass(['date_accomplished' => '2026-08-24', 'target_office' => 'CENRO', ...$submission]);
        expect($model->deadline_submission)->toBe('2026-09-03')
            ->and($model->number_days_complied)->toBe(7);
    }
});

test('IPAF Revenue uses signed centralized working days without changing deadline or revenue splits', function () {
    $revenue = new IpafRevenueCollection([
        'deadline_submission' => '2026-07-20', 'date_received_penro' => '2026-07-27',
        'date_report_released_cenro' => '2026-07-01', 'total_collected' => '1000.00',
    ]);

    expect($revenue->number_days_complied)->toBe(-4)
        ->and($revenue->timeliness)->toBe('Poor')
        ->and($revenue->deadline_submission->toDateString())->toBe('2026-07-20')
        ->and($revenue->ipaf_ria)->toBe('750.00')
        ->and($revenue->sagf)->toBe('250.00');

    NonWorkingDay::create([
        'date' => '2026-07-21', 'name' => 'Registered holiday', 'type' => NonWorkingDay::TYPE_NATIONAL_HOLIDAY,
        'scope' => NonWorkingDay::SCOPE_NATIONAL, 'is_active' => true,
    ]);
    expect($revenue->number_days_complied)->toBe(-3);
});
