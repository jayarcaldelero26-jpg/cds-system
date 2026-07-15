<?php

namespace App\Http\Controllers;

use App\Models\EcotourismMonitoring;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class EcotourismMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $query = EcotourismMonitoring::with('protectedArea');

        // Search Filters
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('site_name', 'like', "%{$search}%")
                  ->orWhere('impact_rating', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhereHas('protectedArea', function ($p) use ($search) {
                      $p->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('protected_area_id')) {
            $query->where('protected_area_id', $request->input('protected_area_id'));
        }

        if ($request->filled('impact_rating')) {
            $query->where('impact_rating', $request->input('impact_rating'));
        }

        $monitorings = $query->latest()->paginate(10)->withQueryString();

        // I-transform ang data para hapsay basahon sa React
        $monitorings->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'protected_area_id' => $item->protected_area_id,
                'protected_area_name' => $item->protectedArea->name ?? 'Unknown',
                'site_name' => $item->site_name,
                'monitoring_date' => $item->monitoring_date ? $item->monitoring_date->format('Y-m-d') : null,
                'visitors_count' => $item->visitors_count,
                'impact_rating' => $item->impact_rating,
                'issues_observed' => $item->issues_observed,
                'recommendations' => $item->recommendations,
                'status' => $item->status,
                'attachment' => $item->attachment,
            ];
        });

        return Inertia::render('EcotourismMonitorings/Index', [
            'monitorings' => $monitorings,
            'filters' => $request->only(['search', 'protected_area_id', 'impact_rating']),
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'impactRatings' => ['Low', 'Moderate', 'High'],
        ]);
    }

    public function create()
    {
        return Inertia::render('EcotourismMonitorings/Create', [
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'impactRatings' => ['Low', 'Moderate', 'High'],
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'site_name' => 'required|string|max:255',
            'monitoring_date' => 'required|date',
            'visitors_count' => 'required|integer|min:0',
            'impact_rating' => 'required|string|in:Low,Moderate,High',
            'issues_observed' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'status' => 'required|string|in:Under Review,Approved',
            'attachment' => 'nullable|file|mimes:pdf|max:20480', // Max 20MB
        ]);

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ecotourism-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        EcotourismMonitoring::create($validated);

        return redirect()->route('ecotourism-monitorings.index')
            ->with('status', 'ecotourism-monitoring-created');
    }

    public function edit(EcotourismMonitoring $ecotourismMonitoring)
    {
        // Gi-format ang data daan aron dili mag-error ang HTML5 Date input sa React
        return Inertia::render('EcotourismMonitorings/Edit', [
            'monitoring' => [
                'id' => $ecotourismMonitoring->id,
                'protected_area_id' => $ecotourismMonitoring->protected_area_id,
                'site_name' => $ecotourismMonitoring->site_name,
                'monitoring_date' => $ecotourismMonitoring->monitoring_date ? $ecotourismMonitoring->monitoring_date->format('Y-m-d') : null,
                'visitors_count' => $ecotourismMonitoring->visitors_count,
                'impact_rating' => $ecotourismMonitoring->impact_rating,
                'issues_observed' => $ecotourismMonitoring->issues_observed,
                'recommendations' => $ecotourismMonitoring->recommendations,
                'status' => $ecotourismMonitoring->status,
                'attachment' => $ecotourismMonitoring->attachment,
            ],
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'impactRatings' => ['Low', 'Moderate', 'High'],
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function update(Request $request, EcotourismMonitoring $ecotourismMonitoring)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'site_name' => 'required|string|max:255',
            'monitoring_date' => 'required|date',
            'visitors_count' => 'required|integer|min:0',
            'impact_rating' => 'required|string|in:Low,Moderate,High',
            'issues_observed' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'status' => 'required|string|in:Under Review,Approved',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            // I-delete ang karaan nga file aron dili mapuno ang server storage
            if ($ecotourismMonitoring->attachment) {
                Storage::disk('public')->delete($ecotourismMonitoring->attachment);
            }
            $path = $request->file('attachment')->store('ecotourism-monitorings', 'public');
            $validated['attachment'] = $path;
        }

        $ecotourismMonitoring->update($validated);

        return redirect()->route('ecotourism-monitorings.index')
            ->with('status', 'ecotourism-monitoring-updated');
    }

    public function destroy(EcotourismMonitoring $ecotourismMonitoring)
    {
        if ($ecotourismMonitoring->attachment) {
            Storage::disk('public')->delete($ecotourismMonitoring->attachment);
        }

        $ecotourismMonitoring->delete();

        return redirect()->route('ecotourism-monitorings.index')
            ->with('status', 'ecotourism-monitoring-deleted');
    }
}
