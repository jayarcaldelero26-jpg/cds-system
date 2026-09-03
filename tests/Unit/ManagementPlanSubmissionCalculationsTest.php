<?php

namespace Tests\Unit;

use App\Models\ManagementPlan;
use App\Services\BusinessCalendarService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManagementPlanSubmissionCalculationsTest extends TestCase
{
    public function test_requested_submission_example_and_calendar_delay(): void
    {
        $plan = new ManagementPlan([
            'date_accomplished' => '2026-03-23',
            'date_received_penro' => '2026-03-25',
            'date_endorsed_regional' => '2026-03-30',
        ]);

        $this->assertSame('2026-04-01', $plan->deadline_submission);
        $this->assertSame(2, $plan->number_days_complied);
        $this->assertSame('Outstanding', $plan->timeliness);
        $this->assertSame('Pending Submission by CENRO', $plan->submission_status);
        $this->assertSame(5, $plan->total_days_delayed_penro);
    }

    #[DataProvider('timelinessBoundaries')]
    public function test_standard_b_timeliness_boundaries(int $days, string $expected): void
    {
        $start = Carbon::parse('2026-01-05');
        $calendar = new BusinessCalendarService;
        $plan = new ManagementPlan([
            'date_accomplished' => $start->toDateString(),
            'date_received_penro' => $calendar->addWorkingDays($start, $days, null, BusinessCalendarService::STANDARD_WORKING_WEEKDAYS)->toDateString(),
        ]);

        $this->assertSame($days, $plan->number_days_complied);
        $this->assertSame($expected, $plan->timeliness);
    }

    public static function timelinessBoundaries(): array
    {
        return [
            [5, 'Outstanding'],
            [6, 'Very Satisfactory'],
            [7, 'Satisfactory'],
            [8, 'Unsatisfactory'],
            [13, 'Unsatisfactory'],
            [14, 'Poor'],
            [62, 'Poor'],
            [63, 'No Rating'],
        ];
    }
}
