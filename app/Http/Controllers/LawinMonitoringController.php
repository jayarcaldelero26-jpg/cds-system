<?php

namespace App\Http\Controllers;

use App\Models\LawinMonitoring;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LawinMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $query = LawinMonitoring::with('protectedArea');

        // Search Filters
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('threats_observed', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%")
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

        $monitorings = $query->latest()->paginate(10)->withQueryString();

        // I-format ang data para basahon sa React frontend
        $monitorings->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'protected_area_id' => $item->protected_area_id,
                'protected_area_name' => $item->protectedArea->name ?? 'Unknown',
                'patrol_date' => $item->patrol_date ? $item->patrol_date->format('Y-m-d') : null,
                'patrol_distance' => $item->patrol_distance,
                'patrol_hours' => $item->patrol_hours,
                'patrol_members_count' => $item->patrol_members_count,
                'threats_observed' => $item->threats_observed,
                'remarks' => $item->remarks,
                'status' => $item->status,
                'attachment' => $item->attachment,
            ];
        });

        return Inertia::render('LawinMonitorings/Index', [
            'monitorings' => $monitorings,
            'filters' => $request->only(['search', 'protected_area_id', 'status']),
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function create()
    {
        return Inertia::render('LawinMonitorings/Create', [
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'patrol_date' => 'required|date',
            'patrol_distance' => 'required|numeric|min:0',
            'patrol_hours' => 'required|numeric|min:0',
            'patrol_members_count' => 'required|integer|min:1',
            'threats_observed' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'required|string|in:Under Review,Approved',
            'attachment' => 'nullable|file|mimes:pdf|max:20480', // PDF limit 20MB
        ]);

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('lawin-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        LawinMonitoring::create($validated);

        return redirect()->route('lawin-monitorings.index')
            ->with('status', 'lawin-monitoring-created');
    }

    public function edit(LawinMonitoring $lawinMonitoring)
    {
        return Inertia::render('LawinMonitorings/Edit', [
            'monitoring' => [
                'id' => $lawinMonitoring->id,
                'protected_area_id' => $lawinMonitoring->protected_area_id,
                'patrol_date' => $lawinMonitoring->patrol_date ? $lawinMonitoring->patrol_date->format('Y-m-d') : null,
                'patrol_distance' => $lawinMonitoring->patrol_distance,
                'patrol_hours' => $lawinMonitoring->patrol_hours,
                'patrol_members_count' => $lawinMonitoring->patrol_members_count,
                'threats_observed' => $lawinMonitoring->threats_observed,
                'remarks' => $lawinMonitoring->remarks,
                'status' => $lawinMonitoring->status,
                'attachment' => $lawinMonitoring->attachment,
            ],
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function update(Request $request, LawinMonitoring $lawinMonitoring)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'patrol_date' => 'required|date',
            'patrol_distance' => 'required|numeric|min:0',
            'patrol_hours' => 'required|numeric|min:0',
            'patrol_members_count' => 'required|integer|min:1',
            'threats_observed' => 'nullable|string',
            'remarks' => 'nullable|string',
            'status' => 'required|string|in:Under Review,Approved',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            if ($lawinMonitoring->attachment) {
                Storage::disk('public')->delete($lawinMonitoring->attachment);
            }
            $path = $request->file('attachment')->store('lawin-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        $lawinMonitoring->update($validated);

        return redirect()->route('lawin-monitorings.index')
            ->with('status', 'lawin-monitoring-updated');
    }

    public function destroy(LawinMonitoring $lawinMonitoring)
    {
        if ($lawinMonitoring->attachment) {
            Storage::disk('public')->delete($lawinMonitoring->attachment);
        }
        $lawinMonitoring->delete();

        return redirect()->route('lawin-monitorings.index')
            ->with('status', 'lawin-monitoring-deleted');
    }
}
