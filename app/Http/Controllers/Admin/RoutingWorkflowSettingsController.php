<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubmissionTracking\RoutingPositionSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RoutingWorkflowSettingsController extends Controller
{
    public function index(RoutingPositionSettingsService $settings): Response
    {
        return Inertia::render('Admin/Settings/RoutingWorkflow', [
            'settings' => $settings->current(),
            'canUpdate' => (bool) request()->user()?->can('submission-tracking.routing-settings.update'),
        ]);
    }

    public function update(Request $request, RoutingPositionSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'office_penro_enabled' => ['required', 'boolean'],
            'penro_tsd_chief_enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $settings->save(
            (int) $validated['expected_version'],
            filter_var($validated['office_penro_enabled'], FILTER_VALIDATE_BOOLEAN),
            filter_var($validated['penro_tsd_chief_enabled'], FILTER_VALIDATE_BOOLEAN),
            $validated['reason'] ?? null,
            $request->user(),
        );

        return back()->with('success', 'Report routing settings saved. They apply only to eligible new routes.');
    }
}
