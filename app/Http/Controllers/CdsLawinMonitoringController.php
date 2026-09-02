<?php

namespace App\Http\Controllers;

use App\Models\CdsLawinMonitoring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class CdsLawinMonitoringController extends Controller
{
    public function index(Request $request)
    {
        abort(404, 'This monitoring module has been retired and is no longer available.');
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
        abort(404, 'This monitoring module has been retired and is no longer available.');
        return Inertia::render('CdsLawin/Create', [
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function store(Request $request)
    {
        abort(404, 'This monitoring module has been retired and is no longer available.');
        $validated = $request->validate($this->validationRules());
        $newAttachment = null;

        if ($request->hasFile('attachment')) {
            $newAttachment = $request->file('attachment')->store('cds-lawin-monitorings', 'public');
            $validated['attachment'] = $newAttachment;
        }

        $validated['user_id'] = $request->user()->id;

        try {
            DB::transaction(fn () => CdsLawinMonitoring::create($validated));
        } catch (Throwable $exception) {
            if ($newAttachment) {
                Storage::disk('public')->delete($newAttachment);
            }

            throw $exception;
        }

        return redirect()->route('cds-lawin.index')
            ->with('success', 'CDS LAWIN monitoring record created successfully.');
    }

    public function edit(CdsLawinMonitoring $cdsLawinMonitoring)
    {
        abort(404, 'This monitoring module has been retired and is no longer available.');
        return Inertia::render('CdsLawin/Edit', [
            'lawin' => [
                'id' => $cdsLawinMonitoring->id,
                'patrol_area' => $cdsLawinMonitoring->patrol_area,
                'patrol_date' => $cdsLawinMonitoring->patrol_date?->format('Y-m-d'),
                'ecoregion' => $cdsLawinMonitoring->ecoregion,
                'team_leader' => $cdsLawinMonitoring->team_leader,
                'team_members_count' => $cdsLawinMonitoring->team_members_count,
                'threats_observed' => $cdsLawinMonitoring->threats_observed,
                'remarks' => $cdsLawinMonitoring->remarks,
                'status' => $cdsLawinMonitoring->status,
                'attachment' => $cdsLawinMonitoring->attachment,
            ],
            'statuses' => ['Under Review', 'Approved'],
        ]);
    }

    public function update(Request $request, CdsLawinMonitoring $cdsLawinMonitoring)
    {
        abort(404, 'This monitoring module has been retired and is no longer available.');
        $validated = $request->validate($this->validationRules());
        $oldAttachment = $cdsLawinMonitoring->attachment;
        $newAttachment = null;

        if ($request->hasFile('attachment')) {
            $newAttachment = $request->file('attachment')->store('cds-lawin-monitorings', 'public');
            $validated['attachment'] = $newAttachment;
        }

        try {
            DB::transaction(fn () => $cdsLawinMonitoring->update($validated));
        } catch (Throwable $exception) {
            if ($newAttachment) {
                Storage::disk('public')->delete($newAttachment);
            }

            throw $exception;
        }

        if ($newAttachment && $oldAttachment) {
            Storage::disk('public')->delete($oldAttachment);
        }

        return redirect()->route('cds-lawin.index')
            ->with('success', 'CDS LAWIN monitoring record updated successfully.');
    }

    public function destroy(CdsLawinMonitoring $cdsLawinMonitoring)
    {
        abort(404, 'This monitoring module has been retired and is no longer available.');
        $attachment = $cdsLawinMonitoring->attachment;

        DB::transaction(fn () => $cdsLawinMonitoring->delete());

        if ($attachment) {
            Storage::disk('public')->delete($attachment);
        }

        return redirect()->route('cds-lawin.index')
            ->with('success', 'CDS LAWIN monitoring record deleted successfully.');
    }

    private function validationRules(): array
    {
        return [
            'patrol_area' => ['required', 'string', 'max:255'],
            'patrol_date' => ['required', 'date'],
            'ecoregion' => ['nullable', 'string', 'max:255'],
            'team_leader' => ['nullable', 'string', 'max:255'],
            'team_members_count' => ['required', 'integer', 'min:1'],
            'threats_observed' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['Under Review', 'Approved'])],
            'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ];
    }
}
