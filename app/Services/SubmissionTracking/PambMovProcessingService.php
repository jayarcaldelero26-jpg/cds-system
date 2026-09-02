<?php

namespace App\Services\SubmissionTracking;

use App\Models\ConservationReportSubmission;
use App\Models\PambMovReviewEvent;
use App\Models\User;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use App\Services\Authorization\OrganizationalAccessService;

/** Additive CENRO MOV milestone monitoring; it never supplies compliance values. */
final class PambMovProcessingService
{
    public const ACTIVITY_CONDUCTED = 'activity_conducted';
    public const MOV_UPLOADED = 'mov_uploaded';
    public const SUBMITTED_FOR_REVIEW = 'submitted_for_review';
    public const RESUBMITTED_FOR_REVIEW = 'resubmitted_for_review';
    public const NEEDS_CORRECTION = 'needs_correction';
    public const READY_FOR_RELEASE = 'ready_for_release';
    public const RELEASED_BY_CENRO = 'released_by_cenro';
    public const RECEIVED_BY_PENRO = 'received_by_penro';

    public function __construct(
        private readonly PambComplianceCalculator $compliance,
        private readonly BusinessCalendarService $calendar,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
        private readonly PambSubmissionAccessService $access,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function isApplicable(ConservationReportSubmission $submission): bool
    {
        return $this->compliance->isMeeting($submission->workflow_key);
    }

    /** @return array<string, mixed> */
    public function present(ConservationReportSubmission $submission): array
    {
        $submission->loadMissing('movReviewEvents.recordedBy');
        $status = $this->status($submission);
        $storedReviewStatus = $submission->getAttribute('mov_processing_status');
        $chiefVerdict = in_array($storedReviewStatus, [self::READY_FOR_RELEASE, self::NEEDS_CORRECTION], true)
            ? $storedReviewStatus
            : null;
        $hasMov = filled($submission->mov_file_path);
        $percent = match ($status) {
            self::RELEASED_BY_CENRO, self::RECEIVED_BY_PENRO => 100,
            self::READY_FOR_RELEASE => 70,
            self::SUBMITTED_FOR_REVIEW, self::NEEDS_CORRECTION => 35,
            default => $hasMov ? 35 : 0,
        };
        $pendingSince = match ($status) {
            self::ACTIVITY_CONDUCTED => $this->compliance->authoritativeDate($submission)?->toDateString(),
            self::SUBMITTED_FOR_REVIEW => $submission->mov_submitted_at?->toDateString(),
            self::NEEDS_CORRECTION, self::READY_FOR_RELEASE => $submission->mov_reviewed_at?->toDateString(),
            default => null,
        };
        $today = CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->startOfDay();
        $workingDaysAtStage = $pendingSince
            ? $this->calendar->workingDaysBetween($pendingSince, $today, 'after_through', $submission->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS)
            : null;
        $reviewEvents = $submission->relationLoaded('movReviewEvents')
            ? $submission->movReviewEvents->sortBy('created_at')->values()
            : collect();

        return [
            'applicable' => true,
            'percent' => $percent,
            'status_key' => $status,
            'status_label' => $status === self::ACTIVITY_CONDUCTED && $hasMov ? 'MOV Uploaded / Ready for Submission' : $this->label($status),
            'workflow_status' => $this->workflowStatus($status, $submission),
            'queue' => $this->queue($status),
            'review_remarks' => $submission->mov_review_remarks,
            'submitted_at' => $submission->mov_submitted_at?->toIso8601String(),
            'reviewed_at' => $submission->mov_reviewed_at?->toIso8601String(),
            'reviewed_by' => $submission->movReviewedBy?->name,
            'chief_verdict' => $chiefVerdict,
            'chief_verdict_label' => $chiefVerdict === self::READY_FOR_RELEASE ? 'Ready for Release' : ($chiefVerdict === self::NEEDS_CORRECTION ? 'Needs Correction' : null),
            'pending_since' => $pendingSince,
            'working_days_at_current_stage' => $workingDaysAtStage,
            'cenro_review' => $this->cenroReviewSummary($submission, $status, $reviewEvents),
            'milestones' => [
                ['key' => self::ACTIVITY_CONDUCTED, 'label' => 'Activity Conducted', 'complete' => true, 'current' => false],
                ['key' => self::SUBMITTED_FOR_REVIEW, 'label' => $status === self::ACTIVITY_CONDUCTED && $hasMov ? 'MOV Uploaded / Ready for Submission' : 'MOV Submitted for Review', 'complete' => in_array($status, [self::SUBMITTED_FOR_REVIEW, self::NEEDS_CORRECTION, self::READY_FOR_RELEASE, self::RELEASED_BY_CENRO, self::RECEIVED_BY_PENRO], true), 'current' => ($status === self::ACTIVITY_CONDUCTED && $hasMov) || in_array($status, [self::SUBMITTED_FOR_REVIEW, self::NEEDS_CORRECTION], true)],
                ['key' => self::READY_FOR_RELEASE, 'label' => $this->routingPolicy->isDirectPenro($submission) ? 'Reviewed / Ready for PENRO Receipt' : 'Reviewed by CENRO CDS Chief - Ready for Release', 'complete' => in_array($status, [self::READY_FOR_RELEASE, self::RELEASED_BY_CENRO, self::RECEIVED_BY_PENRO], true), 'current' => $status === self::READY_FOR_RELEASE],
                ['key' => $this->routingPolicy->isDirectPenro($submission) ? self::RECEIVED_BY_PENRO : self::RELEASED_BY_CENRO, 'label' => $this->routingPolicy->isDirectPenro($submission) ? 'Received by PENRO' : 'Released by CENRO to PENRO', 'complete' => in_array($status, [self::RELEASED_BY_CENRO, self::RECEIVED_BY_PENRO], true), 'current' => false],
            ],
            'turnaround' => $this->turnaround($submission),
            'review_history' => $submission->relationLoaded('movReviewEvents')
                ? $reviewEvents->map(fn (PambMovReviewEvent $event): array => ['event_key' => $event->event_key, 'event_label' => $this->label($event->event_key), 'remarks' => $event->remarks, 'recorded_at' => $event->created_at?->toIso8601String(), 'recorded_by' => $event->recordedBy?->name])->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function cenroReviewSummary(ConservationReportSubmission $submission, string $status, Collection $events): array
    {
        if ($this->routingPolicy->isDirectPenro($submission)) {
            return ['applicable' => false];
        }

        $decisions = $events->filter(fn (PambMovReviewEvent $event): bool => in_array($event->event_key, [self::NEEDS_CORRECTION, self::READY_FOR_RELEASE], true))->values();
        $corrections = $events->filter(fn (PambMovReviewEvent $event): bool => $event->event_key === self::NEEDS_CORRECTION)->values();
        $latestDecision = $decisions->last();
        $latestCorrection = $corrections->last();
        $verdictKey = match ($status) {
            self::NEEDS_CORRECTION => self::NEEDS_CORRECTION,
            self::READY_FOR_RELEASE, self::RELEASED_BY_CENRO, self::RECEIVED_BY_PENRO => self::READY_FOR_RELEASE,
            self::SUBMITTED_FOR_REVIEW => null,
            default => null,
        };
        $verdict = match ($verdictKey) {
            self::NEEDS_CORRECTION => 'Needs Correction',
            self::READY_FOR_RELEASE => 'Ready for Release',
            default => $status === self::SUBMITTED_FOR_REVIEW ? 'Awaiting CENRO CDS Chief Review' : 'No CENRO review verdict recorded',
        };
        $reviewer = $verdictKey && $latestDecision?->event_key === $verdictKey ? $latestDecision->recordedBy : null;
        $category = app(OrganizationalAccessService::class);
        $categoryLabel = fn (?User $user): ?string => $user ? $category->categoryLabel($category->effectiveCategory($user)) : null;
        $previousCorrection = $latestCorrection ? [
            'reviewed_by' => $latestCorrection->recordedBy?->name,
            'reviewed_user_category' => $categoryLabel($latestCorrection->recordedBy),
            'reviewed_at' => $latestCorrection->created_at?->toIso8601String(),
            'reason' => $latestCorrection->remarks,
        ] : null;

        return [
            'applicable' => true,
            'verdict_key' => $verdictKey,
            'verdict' => $verdict,
            'reviewed_by' => $reviewer?->name,
            'reviewed_user_category' => $categoryLabel($reviewer),
            'reviewed_at' => $reviewer ? $latestDecision?->created_at?->toIso8601String() : null,
            'originating_office' => $submission->target_office,
            'remarks' => $reviewer ? $latestDecision?->remarks : null,
            'correction_reason' => $verdictKey === self::NEEDS_CORRECTION ? $latestCorrection?->remarks : null,
            'correction_returned_by' => $verdictKey === self::NEEDS_CORRECTION ? $latestCorrection?->recordedBy?->name : null,
            'correction_returned_at' => $verdictKey === self::NEEDS_CORRECTION ? $latestCorrection?->created_at?->toIso8601String() : null,
            'previous_correction_cycles' => $corrections->count(),
            'previous_correction' => $previousCorrection,
        ];
    }

    public function status(ConservationReportSubmission $submission): string
    {
        if ($this->routingPolicy->isDirectPenro($submission) && $submission->date_received_penro) return self::RECEIVED_BY_PENRO;
        if (! $this->routingPolicy->isDirectPenro($submission) && $submission->date_report_released_cenro) return self::RELEASED_BY_CENRO;
        $stored = $submission->getAttribute('mov_processing_status');
        if (is_string($stored) && in_array($stored, [self::SUBMITTED_FOR_REVIEW, self::NEEDS_CORRECTION, self::READY_FOR_RELEASE], true)) return $stored;
        return self::ACTIVITY_CONDUCTED;
    }

    public function recordUpload(ConservationReportSubmission $submission, User $actor): void
    {
        if (! $this->isApplicable($submission)) return;

        $submission->movReviewEvents()->create([
            'event_key' => self::MOV_UPLOADED,
            'recorded_by' => $actor->id,
        ]);
    }

    public function submit(ConservationReportSubmission $submission, User $actor): void
    {
        $this->assertScoped($submission, $actor);
        abort_unless($this->access->canPerform($actor, 'submit'), 403);
        if (! $submission->mov_file_path) throw ValidationException::withMessages(['mov' => 'Upload the MOV/report before submitting it for review.']);

        DB::transaction(function () use ($submission, $actor): void {
            $locked = ConservationReportSubmission::query()->with('protectedArea')->lockForUpdate()->findOrFail($submission->id);
            $status = $this->status($locked);

            // A repeated request for the same review cycle is a safe no-op.
            // The lock also closes the race between two rapid submissions.
            if ($status === self::SUBMITTED_FOR_REVIEW) return;
            if (! in_array($status, [self::ACTIVITY_CONDUCTED, self::NEEDS_CORRECTION], true)) throw ValidationException::withMessages(['status' => 'This MOV/report is not available for submission.']);

            $isResubmission = $locked->movReviewEvents()->where('event_key', self::NEEDS_CORRECTION)->exists();
            $eventKey = $isResubmission ? self::RESUBMITTED_FOR_REVIEW : self::SUBMITTED_FOR_REVIEW;
            $locked->update(['mov_processing_status' => self::SUBMITTED_FOR_REVIEW, 'mov_submitted_at' => now(), 'mov_submitted_by' => $actor->id, 'mov_reviewed_at' => null, 'mov_reviewed_by' => null, 'mov_review_remarks' => null, 'updated_by' => $actor->id]);
            $locked->movReviewEvents()->create(['event_key' => $eventKey, 'recorded_by' => $actor->id]);
            $this->auditLogs->record('submission_tracking', $isResubmission ? 'PAMB MOV Resubmitted for Review' : 'PAMB MOV Submitted for Review', ConservationReportSubmission::class, $locked->id, 'PAMB', 'MOV/report submitted for CENRO CDS Chief review.', ['event_key' => $eventKey], $actor->id);
        });
    }

    public function review(ConservationReportSubmission $submission, User $actor, string $decision, ?string $remarks = null): void
    {
        $this->assertScoped($submission, $actor);
        abort_unless($this->access->canPerform($actor, 'review'), 403);
        if (! in_array($decision, [self::READY_FOR_RELEASE, self::NEEDS_CORRECTION], true)) throw ValidationException::withMessages(['decision' => 'Choose Ready for Release or Needs Correction.']);
        if ($decision === self::NEEDS_CORRECTION && blank(trim((string) $remarks))) throw ValidationException::withMessages(['remarks' => 'Remarks are required when correction is needed.']);

        DB::transaction(function () use ($submission, $actor, $decision, $remarks): void {
            $locked = ConservationReportSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $currentStatus = $this->status($locked);
            if ($currentStatus === $decision) return;
            if ($currentStatus !== self::SUBMITTED_FOR_REVIEW) throw ValidationException::withMessages(['decision' => 'Only MOVs submitted for review can receive a Chief review decision.']);

            $cleanRemarks = $remarks ? trim($remarks) : null;
            $locked->update(['mov_processing_status' => $decision, 'mov_reviewed_at' => now(), 'mov_reviewed_by' => $actor->id, 'mov_review_remarks' => $cleanRemarks, 'updated_by' => $actor->id]);
            $locked->movReviewEvents()->create(['event_key' => $decision, 'remarks' => $cleanRemarks, 'recorded_by' => $actor->id]);
            $this->auditLogs->record('submission_tracking', $decision === self::READY_FOR_RELEASE ? 'PAMB MOV Marked Ready for Release' : 'PAMB MOV Returned for Correction', ConservationReportSubmission::class, $locked->id, 'PAMB', 'CENRO CDS Chief recorded a MOV/report review decision.', ['event_key' => $decision, 'remarks' => $cleanRemarks], $actor->id);
        });
    }

    /** @return array<string, mixed> */
    private function turnaround(ConservationReportSubmission $submission): array
    {
        $base = $this->compliance->authoritativeDate($submission);
        $deadline = $this->compliance->deadline($submission);
        if (! $base || ! $deadline) return ['label' => 'Not started', 'day' => null, 'remaining' => null, 'deadline' => $deadline];

        $today = CarbonImmutable::now(BusinessCalendarService::TIMEZONE)->startOfDay();
        $due = CarbonImmutable::parse($deadline, BusinessCalendarService::TIMEZONE)->startOfDay();
        $elapsed = $this->calendar->workingDaysBetween($base, $today, 'after_through', $submission->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS);
        if ($today->equalTo($due)) $label = 'DUE TODAY';
        elseif ($today->greaterThan($due)) {
            $overdue = $this->calendar->workingDaysBetween($due, $today, 'after_through', $submission->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS);
            $label = 'OVERDUE BY '.$overdue.' WORKING DAY'.($overdue === 1 ? '' : 'S');
        } else {
            $remaining = $this->calendar->workingDaysBetween($today, $due, 'after_through', $submission->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS);
            $label = 'DAY '.min(7, $elapsed).' OF 7 · '.$remaining.' WORKING DAY'.($remaining === 1 ? '' : 'S').' REMAINING';
        }
        return ['label' => $label, 'day' => min(7, $elapsed), 'remaining' => $today->lessThan($due) ? $this->calendar->workingDaysBetween($today, $due, 'after_through', $submission->target_office, BusinessCalendarService::PAMB_WORKING_WEEKDAYS) : 0, 'deadline' => $deadline];
    }

    private function assertScoped(ConservationReportSubmission $submission, User $actor): void
    {
        abort_unless($this->isApplicable($submission), 422);
        abort_unless($this->access->canView($actor, $submission), 403);
    }

    private function queue(string $status): ?string
    {
        return match ($status) { self::ACTIVITY_CONDUCTED => 'for_submission', self::SUBMITTED_FOR_REVIEW => 'for_review', self::NEEDS_CORRECTION => 'needs_correction', self::READY_FOR_RELEASE => 'for_release', self::RELEASED_BY_CENRO => 'release_history', default => null };
    }

    private function label(string $status): string
    {
        return match ($status) { self::ACTIVITY_CONDUCTED => 'Activity Conducted / Waiting for MOV', self::MOV_UPLOADED => 'MOV Uploaded', self::SUBMITTED_FOR_REVIEW => 'MOV Submitted for Review', self::RESUBMITTED_FOR_REVIEW => 'MOV Resubmitted for Review', self::NEEDS_CORRECTION => 'Needs Correction', self::READY_FOR_RELEASE => 'Ready for Release', self::RELEASED_BY_CENRO => 'Released by CENRO to PENRO', default => 'PENRO-managed workflow' };
    }

    private function workflowStatus(string $status, ConservationReportSubmission $submission): string
    {
        return match ($status) {
            self::SUBMITTED_FOR_REVIEW => 'Awaiting Review by CENRO CDS Chief',
            self::NEEDS_CORRECTION => 'Needs Correction',
            self::READY_FOR_RELEASE => $this->routingPolicy->isDirectPenro($submission) ? 'Ready for PENRO Receipt' : 'Ready for CENRO Records Release',
            self::RELEASED_BY_CENRO => 'Released by CENRO to PENRO',
            self::RECEIVED_BY_PENRO => 'Received by PENRO',
            default => 'Pending Submission by CENRO',
        };
    }
}
