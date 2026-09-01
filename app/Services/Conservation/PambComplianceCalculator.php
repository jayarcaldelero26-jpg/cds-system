<?php

namespace App\Services\Conservation;

use App\Models\ConservationReportSubmission;
use App\Services\BusinessCalendarService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use Carbon\CarbonImmutable;

/**
 * Authoritative date, deadline, and timeliness policy for PAMB workflows.
 */
final class PambComplianceCalculator
{
    /** @var list<string> */
    public const MEETING_WORKFLOWS = ['regular_pamb', 'special_pamb', 'twc_meetings'];

    public const MANUAL_WORKFLOW = 'updating_pamb_manual';

    public function __construct(
        private readonly BusinessCalendarService $calendar,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
    ) {}

    public function applies(?string $workflowKey): bool
    {
        return $this->isMeeting($workflowKey) || $workflowKey === self::MANUAL_WORKFLOW;
    }

    public function isMeeting(?string $workflowKey): bool
    {
        return in_array($workflowKey, self::MEETING_WORKFLOWS, true);
    }

    public function authoritativeDate(ConservationReportSubmission $report): ?CarbonImmutable
    {
        $value = $this->isMeeting($report->workflow_key)
            ? (filled($report->getAttribute('date_accomplished')) ? $report->getAttribute('date_accomplished') : $report->getAttribute('date_conducted'))
            : ($report->workflow_key === self::MANUAL_WORKFLOW ? $report->getAttribute('date_accomplished') : null);

        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function deadline(ConservationReportSubmission $report): ?string
    {
        $baseDate = $this->authoritativeDate($report);
        if (! $baseDate) {
            return null;
        }

        return $this->calendar
            ->addWorkingDays($baseDate, $this->deadlineDays($report), $report->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS)
            ->toDateString();
    }

    public function daysComplied(ConservationReportSubmission $report): int|string|null
    {
        $baseDate = $this->authoritativeDate($report);
        if (! $baseDate) {
            return null;
        }

        if (! $report->date_received_penro) {
            return $this->routingPolicy->isDirectPenro($report) || filled($report->date_report_released_cenro)
                ? 'Pending Receipt by PENRO'
                : 'Pending Submission by CENRO';
        }

        return $this->calendar->workingDaysBetween(
            $baseDate,
            $report->date_received_penro,
            'after_through',
            $report->target_office,
            BusinessCalendarService::PAMB_WORKING_WEEKDAYS,
        );
    }

    public function timeliness(ConservationReportSubmission $report): string
    {
        $days = $this->daysComplied($report);
        if ($days === null) {
            return 'No Data';
        }
        if (! is_int($days)) {
            return $days;
        }

        return $this->isFinalManual($report)
            ? $this->standardARating($days)
            : $this->standardBRating($days);
    }

    /** @return array{working_days:int,timeliness_standard:'A'|'B'} */
    public function submissionRule(ConservationReportSubmission $report): array
    {
        return [
            'working_days' => $this->deadlineDays($report),
            'timeliness_standard' => $this->isFinalManual($report) ? 'A' : 'B',
        ];
    }

    private function deadlineDays(ConservationReportSubmission $report): int
    {
        return $this->isFinalManual($report) ? 15 : 7;
    }

    private function isFinalManual(ConservationReportSubmission $report): bool
    {
        return $report->workflow_key === self::MANUAL_WORKFLOW
            && strcasecmp(trim((string) $report->activity_name), 'Final Updated Manual') === 0;
    }

    private function standardBRating(int $days): string
    {
        return match (true) {
            $days <= 5 => 'Outstanding',
            $days === 6 => 'Very Satisfactory',
            $days === 7 => 'Satisfactory',
            $days <= 13 => 'Unsatisfactory',
            default => 'Poor',
        };
    }

    private function standardARating(int $days): string
    {
        return match (true) {
            $days <= 11 => 'Outstanding',
            $days <= 13 => 'Very Satisfactory',
            $days <= 15 => 'Satisfactory',
            $days <= 29 => 'Unsatisfactory',
            default => 'Poor',
        };
    }
}
