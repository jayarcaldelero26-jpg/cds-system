<?php

namespace App\Http\Controllers;

use App\Models\CdsLawinMonitoring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CdsLawinMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $query = CdsLawinMonitoring::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('patrol_area', 'like', "%{$search}%")
                  ->orWhere('ecoregion', 'like', "%{$search}%")
                  ->orWhere('team_leader', 'like', "%{$search}%")
                  ->orWhere('threats_observed', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%");
            });
        }

        if ($request->filled('patrol_area')) {
            $query->where('patrol_area', $request->input('patrol_area'));
        }

        $monitorings = $query->latest()->paginate(10)->withQueryString();

        $monitorings->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'patrol_area' => $item->patrol_area,
                'patrol_date' => $item->patrol_date ? $item->patrol_date->format('Y-m-d') : null,
                'ecoregion' => $item->ecoregion,
                'team_leader' => $item->team_leader,
                'team_members_count' => $item->team_members_count,
                'threats_observed' => $item->threats_observed,
                'remarks' => $item->remarks,
                'status' => $item->status ?? 'Under Review',
                'attachment' => $item->attachment,
            ];
        });

        return Inertia::render('CdsLawin/Index', [
            'monitorings' => $monitorings,
            'filters' => $request->only(['search', 'patrol_area']),
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function create()
    {
        return Inertia::render('CdsLawin/Create', [
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'patrol_area' => 'required|string|max:255',
            'patrol_date' => 'required|date',
            'ecoregion' => 'nullable|string|max:255',
            'team_leader' => 'nullable|string|max:255',
            'team_members_count' => 'required|integer|min:1',
            'threats_observed' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('cds-lawin-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        CdsLawinMonitoring::create($validated);

        return redirect()->route('cds-lawin.index')
            ->with('status', 'cds-lawin-created');
    }

    public function edit(CdsLawinMonitoring $cdsLawin)
    {
        return Inertia::render('CdsLawin/Edit', [
            'monitoring' => [
                'id' => $cdsLawin->id,
                'patrol_area' => $cdsLawin->patrol_area,
                'patrol_date' => $cdsLawin->patrol_date ? $cdsLawin->patrol_date->format('Y-m-d') : null,
                'ecoregion' => $cdsLawin->ecoregion,
                'team_leader' => $cdsLawin->team_leader,
                'team_members_count' => $cdsLawin->team_members_count,
                'threats_observed' => $cdsLawin->threats_observed,
                'remarks' => $cdsLawin->remarks,
                'status' => $cdsLawin->status,
                'attachment' => $cdsLawin->attachment,
            ],
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function update(Request $request, CdsLawinMonitoring $cdsLawin)
    {
        $validated = $request->validate([
            'patrol_area' => 'required|string|max:255',
            'patrol_date' => 'required|date',
            'ecoregion' => 'nullable|string|max:255',
            'team_leader' => 'nullable|string|max:255',
            'team_members_count' => 'required|integer|min:1',
            'threats_observed' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            if ($cdsLawin->attachment) {
                Storage::disk('public')->delete($cdsLawin->attachment);
            }
            $path = $request->file('attachment')->store('cds-lawin-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        $cdsLawin->update($validated);

        return redirect()->route('cds-lawin.index')
            ->with('status', 'cds-lawin-updated');
    }

    public function destroy(CdsLawinMonitoring $cdsLawin)
    {
        if ($cdsLawin->attachment) {
            Storage::disk('public')->delete($cdsLawin->attachment);
        }
        $cdsLawin->delete();

        return redirect()->route('cds-lawin.index')
            ->with('status', 'cds-lawin-deleted');
    }
}
