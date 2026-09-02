<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\PambMovProcessingService;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\RoutingCorrectionService;
use App\Services\BusinessCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionTrackingController extends Controller
{
    public function __construct(private readonly SubmissionTrackingService $tracking, private readonly RoutingCorrectionService $corrections, private readonly PambMovProcessingService $pambMov, private readonly PambSubmissionAccessService $pambAccess) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'module', 'protected_area_id', 'target_office', 'reporting_period', 'status']);
        $snapshot = $this->tracking->snapshot($filters);
        $records = $snapshot['records'];
        $queues = $snapshot['queues'];
        return Inertia::render('SubmissionTracking/Index', [
            'queues' => $queues,
            'filters' => $filters,
            'filterOptions' => [
                'modules' => $snapshot['modules'],
                'protectedAreas' => $this->pambAccess->isPamo($request->user())
                    ? ProtectedArea::query()->whereKey($request->user()->protected_area_id)->orderBy('name')->get(['id', 'name'])
                    : ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
                'targetOffices' => $records->pluck('target_office')->filter()->unique()->sort()->values(),
                'periods' => $records->pluck('reporting_period')->filter()->unique()->sort()->values(),
                'statuses' => $records->pluck('submission_status')->filter()->unique()->sort()->values(),
            ],
            'trackingContext' => [
                'is_cenro_user' => $this->pambAccess->isCenro($request->user()),
                'is_pamo_user' => $this->pambAccess->isPamo($request->user()),
                'is_global_user' => $this->pambAccess->isGlobal($request->user()),
                'can_use_downstream_operations' => $this->pambAccess->canUseDownstreamOperations($request->user()),
                'can_submit_mov' => $this->pambAccess->canPerform($request->user(), 'submit'),
                'can_review_mov' => $this->pambAccess->canPerform($request->user(), 'review'),
                'can_release_mov' => $this->pambAccess->canPerform($request->user(), 'release'),
            ],
        ]);
    }

    public function transition(Request $request, string $source, int $record, string $stage): RedirectResponse
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        abort_unless($request->user()?->can($sourceConfig['ability']), 403);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'stage' => ['nullable', Rule::in([SubmissionTrackingService::CENRO_RELEASE, SubmissionTrackingService::PENRO_RECEIPT, SubmissionTrackingService::REGIONAL_ENDORSEMENT])],
        ]);
        abort_unless(($data['stage'] ?? $stage) === $stage, 422);

        $this->tracking->transition($source, $record, $stage, $data['date'], $request->user()?->id);

        return back()->with('success', match ($stage) {
            SubmissionTrackingService::CENRO_RELEASE => 'Released by CENRO to PENRO. MOV Processing: 100% complete.',
            SubmissionTrackingService::PENRO_RECEIPT => 'PENRO receipt recorded.',
            default => 'Regional endorsement recorded.',
        });
    }

    public function internalRouting(Request $request, string $source, int $record, string $stage): RedirectResponse
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        abort_unless($request->user()?->can($sourceConfig['ability']), 403);
        if ($source === 'conservation') {
            $submission = \App\Models\ConservationReportSubmission::query()->with('protectedArea')->findOrFail($record);
            abort_unless($this->pambAccess->canView($request->user(), $submission), 403);
        }
        $data = $request->validate([
            'stage' => ['nullable', Rule::in(app(\App\Services\SubmissionTracking\PambRoutingTimelineService::class)->internalStageKeys())],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(($data['stage'] ?? $stage) === $stage, 422);

        abort_unless($this->pambAccess->canUseDownstreamOperations($request->user()), 403);
        $this->tracking->recordInternalRouting($source, $record, $stage, now(BusinessCalendarService::TIMEZONE)->toDateTimeString(), $request->user()?->id, $data['remarks'] ?? null);

        return back()->with('success', 'PAMB internal routing event recorded.');
    }

    public function submitMovForReview(Request $request, string $source, int $record): RedirectResponse
    {
        abort_unless($source === 'conservation', 404);
        $submission = \App\Models\ConservationReportSubmission::query()->with('protectedArea')->findOrFail($record);
        $this->pambMov->submit($submission, $request->user());

        return back()->with('success', 'MOV/report submitted for CENRO CDS Chief review.');
    }

    public function reviewMov(Request $request, string $source, int $record): RedirectResponse
    {
        abort_unless($source === 'conservation', 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in([PambMovProcessingService::READY_FOR_RELEASE, PambMovProcessingService::NEEDS_CORRECTION])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['decision'] === PambMovProcessingService::NEEDS_CORRECTION) {
            validator($data, ['remarks' => ['required', 'string', 'min:5', 'max:2000']])->validate();
        }
        $submission = \App\Models\ConservationReportSubmission::query()->with('protectedArea')->findOrFail($record);
        $this->pambMov->review($submission, $request->user(), $data['decision'], $data['remarks'] ?? null);

        return back()->with('success', $data['decision'] === PambMovProcessingService::READY_FOR_RELEASE ? 'MOV/report marked Ready for Release.' : 'MOV/report returned for correction.');
    }

    public function correctRouting(Request $request, string $source, int $record): RedirectResponse
    {
        $sourceConfig = $this->tracking->source($source);
        abort_unless($sourceConfig, 404);
        $data = $request->validate([
            'dates' => ['required', 'array'],
            'dates.date_report_released_cenro' => ['sometimes', 'nullable', 'date'],
            'dates.date_received_penro' => ['sometimes', 'nullable', 'date'],
            'dates.date_endorsed_regional' => ['sometimes', 'nullable', 'date'],
            'release_events' => ['sometimes', 'array'],
            'release_events.*' => ['nullable', 'date'],
            'internal_events' => ['sometimes', 'array'],
            'internal_events.*' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'password' => ['required', 'current_password:web'],
        ]);

        $this->corrections->correct(
            $source,
            $record,
            $data['dates'],
            $data['release_events'] ?? [],
            trim($data['reason']),
            (int) $request->user()->id,
            $data['internal_events'] ?? [],
        );

        return back()->with('success', 'Routing record corrected successfully.');
    }
}
