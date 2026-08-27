<?php

namespace App\Http\Controllers;

use App\Models\IssueMonitoring;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class IssueMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $query = IssueMonitoring::with('protectedArea');

        // Search Filters
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('issue_description', 'like', "%{$search}%")
                  ->orWhere('findings', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhereHas('protectedArea', function ($p) use ($search) {
                      $p->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('protected_area_id')) {
            $query->where('protected_area_id', $request->input('protected_area_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $issues = $query->latest()->paginate(10)->withQueryString();

        // I-format ang data para hapsay basahon sa React frontend
        $issues->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'protected_area_id' => $item->protected_area_id,
                'protected_area_name' => $item->protectedArea->name ?? 'Unknown',
                'issue_description' => $item->issue_description,
                'findings' => $item->findings,
                'date_observed' => $item->date_observed ? $item->date_observed->format('Y-m-d') : null,
                'recommendations' => $item->recommendations,
                'action_taken' => $item->action_taken,
                'status' => $item->status,
                'attachment' => $item->attachment,
            ];
        });

        return Inertia::render('IssueMonitorings/Index', [
            'issues' => $issues,
            'filters' => $request->only(['search', 'protected_area_id', 'status']),
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'statuses' => ['Pending', 'Ongoing', 'Resolved'],
        ]);
    }

    public function create()
    {
        return Inertia::render('IssueMonitorings/Create', [
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'statuses' => ['Pending', 'Ongoing', 'Resolved'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'issue_description' => 'required|string',
            'findings' => 'required|string',
            'date_observed' => 'required|date',
            'recommendations' => 'nullable|string',
            'action_taken' => 'nullable|string',
            'status' => 'required|string|in:Pending,Ongoing,Resolved',
            'attachment' => 'nullable|file|mimes:pdf|max:20480', // PDF limit 20MB
        ]);

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('issue-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        IssueMonitoring::create($validated);

        return redirect()->route('issue-monitorings.index')
            ->with('success', 'Issue monitoring record created successfully.');
    }

    public function edit(IssueMonitoring $issueMonitoring)
    {
        return Inertia::render('IssueMonitorings/Edit', [
            'issue' => [
                'id' => $issueMonitoring->id,
                'protected_area_id' => $issueMonitoring->protected_area_id,
                'issue_description' => $issueMonitoring->issue_description,
                'findings' => $issueMonitoring->findings,
                'date_observed' => $issueMonitoring->date_observed ? $issueMonitoring->date_observed->format('Y-m-d') : null,
                'recommendations' => $issueMonitoring->recommendations,
                'action_taken' => $issueMonitoring->action_taken,
                'status' => $issueMonitoring->status,
                'attachment' => $issueMonitoring->attachment,
            ],
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'statuses' => ['Pending', 'Ongoing', 'Resolved'],
        ]);
    }

    public function update(Request $request, IssueMonitoring $issueMonitoring)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'issue_description' => 'required|string',
            'findings' => 'required|string',
            'date_observed' => 'required|date',
            'recommendations' => 'nullable|string',
            'action_taken' => 'nullable|string',
            'status' => 'required|string|in:Pending,Ongoing,Resolved',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            // Delete configuration file inside storage if replacing
            if ($issueMonitoring->attachment) {
                Storage::disk('public')->delete($issueMonitoring->attachment);
            }
            $path = $request->file('attachment')->store('issue-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        $issueMonitoring->update($validated);

        return redirect()->route('issue-monitorings.index')
            ->with('success', 'Issue monitoring record updated successfully.');
    }

    public function destroy(IssueMonitoring $issueMonitoring)
    {
        if ($issueMonitoring->attachment) {
            Storage::disk('public')->delete($issueMonitoring->attachment);
        }
        $issueMonitoring->delete();

        return redirect()->route('issue-monitorings.index')
            ->with('success', 'Issue monitoring record deleted successfully.');
    }
}
