<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use App\Services\SubmissionTracking\RoutingCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionTrackingController extends Controller
{
    public function __construct(private readonly SubmissionTrackingService $tracking, private readonly RoutingCorrectionService $corrections) {}

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
                'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
                'targetOffices' => $records->pluck('target_office')->filter()->unique()->sort()->values(),
                'periods' => $records->pluck('reporting_period')->filter()->unique()->sort()->values(),
                'statuses' => $records->pluck('submission_status')->filter()->unique()->sort()->values(),
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
            SubmissionTrackingService::CENRO_RELEASE => 'CENRO release recorded.',
            SubmissionTrackingService::PENRO_RECEIPT => 'PENRO receipt recorded.',
            default => 'Regional endorsement recorded.',
        });
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
        );

        return back()->with('success', 'Routing record corrected successfully.');
    }
}
