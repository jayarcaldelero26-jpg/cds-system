<?php

namespace App\Http\Controllers;

use App\Models\LawinMonitoring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class LawinMonitoringController extends Controller
{
    // Static list sa CENRO Offices (gi-dugang na ang CENRO Mati)
    private $cenroList = [
        'CENRO Lupon',
        'CENRO Mati',
        'CENRO Manay',
        'CENRO Baganga',
        'PENRO Main Office',
    ];

    public function index(Request $request)
    {
        $query = LawinMonitoring::query();

        // Search Filters
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('cenro', 'like', "%{$search}%")
                  ->orWhere('threats_observed', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($request->filled('cenro')) {
            $query->where('cenro', $request->input('cenro'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $monitorings = $query->latest()->paginate(10)->withQueryString();

        // I-format ang data para basahon sa React frontend
        $monitorings->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'cenro' => $item->cenro,
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
            'filters' => $request->only(['search', 'cenro', 'status']),
            'cenroList' => $this->cenroList,
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function create()
    {
        return Inertia::render('LawinMonitorings/Create', [
            'cenroList' => $this->cenroList,
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'cenro' => 'required|string',
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
            ->with('success', 'LAWIN monitoring record created successfully.');
    }

    public function edit(LawinMonitoring $lawinMonitoring)
    {
        return Inertia::render('LawinMonitorings/Edit', [
            'monitoring' => [
                'id' => $lawinMonitoring->id,
                'cenro' => $lawinMonitoring->cenro,
                'patrol_date' => $lawinMonitoring->patrol_date ? $lawinMonitoring->patrol_date->format('Y-m-d') : null,
                'patrol_distance' => $lawinMonitoring->patrol_distance,
                'patrol_hours' => $lawinMonitoring->patrol_hours,
                'patrol_members_count' => $lawinMonitoring->patrol_members_count,
                'threats_observed' => $lawinMonitoring->threats_observed,
                'remarks' => $lawinMonitoring->remarks,
                'status' => $lawinMonitoring->status,
                'attachment' => $lawinMonitoring->attachment,
            ],
            'cenroList' => $this->cenroList,
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function update(Request $request, LawinMonitoring $lawinMonitoring)
    {
        $validated = $request->validate([
            'cenro' => 'required|string',
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
            ->with('success', 'LAWIN monitoring record updated successfully.');
    }

    public function destroy(LawinMonitoring $lawinMonitoring)
    {
        if ($lawinMonitoring->attachment) {
            Storage::disk('public')->delete($lawinMonitoring->attachment);
        }
        $lawinMonitoring->delete();

        return redirect()->route('lawin-monitorings.index')
            ->with('success', 'LAWIN monitoring record deleted successfully.');
    }
}
