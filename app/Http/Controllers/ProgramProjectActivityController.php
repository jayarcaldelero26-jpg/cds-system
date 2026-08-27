<?php

namespace App\Http\Controllers;

use App\Models\ProgramProjectActivity;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProgramProjectActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = ProgramProjectActivity::with('protectedArea');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('source_of_fund', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%")
                  ->orWhereHas('protectedArea', function ($p) use ($search) {
                      $p->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('protected_area_id')) {
            $query->where('protected_area_id', $request->input('protected_area_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $ppas = $query->latest()->paginate(10)->withQueryString();

        $ppas->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'protected_area_id' => $item->protected_area_id,
                'protected_area_name' => $item->protectedArea->name ?? 'Unknown',
                'title' => $item->title,
                'category' => $item->category,
                'description' => $item->description,
                'budget' => $item->budget,
                'source_of_fund' => $item->source_of_fund,
                'start_date' => $item->start_date ? $item->start_date->format('Y-m-d') : null,
                'end_date' => $item->end_date ? $item->end_date->format('Y-m-d') : null,
                'status' => $item->status,
                'remarks' => $item->remarks,
                'attachment' => $item->attachment,
            ];
        });

        return Inertia::render('ProgramProjectActivities/Index', [
            'ppas' => $ppas,
            'filters' => $request->only(['search', 'protected_area_id', 'category', 'status']),
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'categories' => ['Program', 'Project', 'Activity'],
            'statuses' => ['Proposed', 'Ongoing', 'Completed', 'Terminated'],
        ]);
    }

    public function create()
    {
        return Inertia::render('ProgramProjectActivities/Create', [
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'categories' => ['Program', 'Project', 'Activity'],
            'statuses' => ['Proposed', 'Ongoing', 'Completed', 'Terminated'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:Program,Project,Activity',
            'description' => 'nullable|string',
            'budget' => 'required|numeric|min:0',
            'source_of_fund' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|string|in:Proposed,Ongoing,Completed,Terminated',
            'remarks' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ppa-attachments', 'public');
            $validated['attachment'] = $path;
        }

        ProgramProjectActivity::create($validated);

        return redirect()->route('program-project-activities.index')
            ->with('success', 'Program, project, or activity record created successfully.');
    }

    public function edit(ProgramProjectActivity $programProjectActivity)
    {
        return Inertia::render('ProgramProjectActivities/Edit', [
            'ppa' => [
                'id' => $programProjectActivity->id,
                'protected_area_id' => $programProjectActivity->protected_area_id,
                'title' => $programProjectActivity->title,
                'category' => $programProjectActivity->category,
                'description' => $programProjectActivity->description,
                'budget' => $programProjectActivity->budget,
                'source_of_fund' => $programProjectActivity->source_of_fund,
                'start_date' => $programProjectActivity->start_date ? $programProjectActivity->start_date->format('Y-m-d') : null,
                'end_date' => $programProjectActivity->end_date ? $programProjectActivity->end_date->format('Y-m-d') : null,
                'status' => $programProjectActivity->status,
                'remarks' => $programProjectActivity->remarks,
                'attachment' => $programProjectActivity->attachment,
            ],
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'categories' => ['Program', 'Project', 'Activity'],
            'statuses' => ['Proposed', 'Ongoing', 'Completed', 'Terminated'],
        ]);
    }

    public function update(Request $request, ProgramProjectActivity $programProjectActivity)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:Program,Project,Activity',
            'description' => 'nullable|string',
            'budget' => 'required|numeric|min:0',
            'source_of_fund' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|string|in:Proposed,Ongoing,Completed,Terminated',
            'remarks' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        if ($request->hasFile('attachment')) {
            if ($programProjectActivity->attachment) {
                Storage::disk('public')->delete($programProjectActivity->attachment);
            }
            $path = $request->file('attachment')->store('ppa-attachments', 'public');
            $validated['attachment'] = $path;
        }

        $programProjectActivity->update($validated);

        return redirect()->route('program-project-activities.index')
            ->with('success', 'Program, project, or activity record updated successfully.');
    }

    public function destroy(ProgramProjectActivity $programProjectActivity)
    {
        if ($programProjectActivity->attachment) {
            Storage::disk('public')->delete($programProjectActivity->attachment);
        }
        $programProjectActivity->delete();

        return redirect()->route('program-project-activities.index')
            ->with('success', 'Program, project, or activity record deleted successfully.');
    }
}
