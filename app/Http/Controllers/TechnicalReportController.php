<?php

namespace App\Http\Controllers;

use App\Models\TechnicalReport;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TechnicalReportController extends Controller
{
    public function index(Request $request)
    {
        $query = TechnicalReport::with('protectedArea');

        // Mga Filters para sa Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('report_type', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%")
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

        if ($request->filled('report_type')) {
            $query->where('report_type', $request->input('report_type'));
        }

        if ($request->filled('reporting_year')) {
            $query->where('reporting_year', $request->input('reporting_year'));
        }

        $reports = $query->latest()->paginate(10)->withQueryString();

        // I-format ang data sa dili pa ipadala sa React frontend
        $reports->getCollection()->transform(function ($report) {
            return [
                'id' => $report->id,
                'protected_area_id' => $report->protected_area_id,
                'protected_area_name' => $report->protectedArea->name ?? 'Unknown',
                'report_type' => $report->report_type,
                'reporting_year' => $report->reporting_year,
                'quarter' => $report->quarter,
                'submission_date' => $report->submission_date ? $report->submission_date->format('Y-m-d') : null,
                'status' => $report->status,
                'attachment' => $report->attachment,
                'remarks' => $report->remarks,
            ];
        });

        $reportTypes = ['AWS', 'Biodiversity Assessment', 'Socio-Economic Assessment', 'Ecotourism Monitoring', 'Activity Report'];
        $statuses = ['Submitted', 'Pending', 'Delayed'];

        return Inertia::render('TechnicalReports/Index', [
            'technicalReports' => $reports,
            'filters' => $request->only(['search', 'protected_area_id', 'status', 'report_type', 'reporting_year']),
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'reportTypes' => $reportTypes,
            'statuses' => $statuses,
        ]);
    }

    public function create()
    {
        return Inertia::render('TechnicalReports/Create', [
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'reportTypes' => ['AWS', 'Biodiversity Assessment', 'Socio-Economic Assessment', 'Ecotourism Monitoring', 'Activity Report'],
            'statuses' => ['Submitted', 'Pending', 'Delayed'],
            'quarters' => ['Q1', 'Q2', 'Q3', 'Q4', 'Annual'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'report_type' => 'required|string|max:255',
            'reporting_year' => 'required|integer|min:2000|max:' . (date('Y') + 5),
            'quarter' => 'nullable|string|in:Q1,Q2,Q3,Q4,Annual',
            'submission_date' => 'nullable|date',
            'status' => 'required|string|in:Submitted,Pending,Delayed',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx|max:20480', // Max 20MB
            'remarks' => 'nullable|string',
        ]);

        if ($request->hasFile('attachment')) {
            // I-save ang file sulod sa storage/app/public/technical-reports
            $path = $request->file('attachment')->store('technical-reports', 'public');
            $validated['attachment'] = $path;
        }

        TechnicalReport::create($validated);

        return redirect()->route('technical-reports.index')
            ->with('status', 'technical-report-created');
    }

    public function edit(TechnicalReport $technicalReport)
    {
        return Inertia::render('TechnicalReports/Edit', [
            'technicalReport' => $technicalReport,
            'protectedAreas' => ProtectedArea::select('id', 'name')->get(),
            'reportTypes' => ['AWS', 'Biodiversity Assessment', 'Socio-Economic Assessment', 'Ecotourism Monitoring', 'Activity Report'],
            'statuses' => ['Submitted', 'Pending', 'Delayed'],
            'quarters' => ['Q1', 'Q2', 'Q3', 'Q4', 'Annual'],
        ]);
    }

    public function update(Request $request, TechnicalReport $technicalReport)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'report_type' => 'required|string|max:255',
            'reporting_year' => 'required|integer|min:2000|max:' . (date('Y') + 5),
            'quarter' => 'nullable|string|in:Q1,Q2,Q3,Q4,Annual',
            'submission_date' => 'nullable|date',
            'status' => 'required|string|in:Submitted,Pending,Delayed',
            'attachment' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx|max:20480',
            'remarks' => 'nullable|string',
        ]);

        if ($request->hasFile('attachment')) {
            // I-delete ang karaan nga file kon naa man gani
            if ($technicalReport->attachment) {
                Storage::disk('public')->delete($technicalReport->attachment);
            }
            $path = $request->file('attachment')->store('technical-reports', 'public');
            $validated['attachment'] = $path;
        }

        $technicalReport->update($validated);

        return redirect()->route('technical-reports.index')
            ->with('status', 'technical-report-updated');
    }

    public function destroy(TechnicalReport $technicalReport)
    {
        // I-delete ang file sa dili pa i-delete ang record sa database
        if ($technicalReport->attachment) {
            Storage::disk('public')->delete($technicalReport->attachment);
        }

        $technicalReport->delete();

        return redirect()->route('technical-reports.index')
            ->with('status', 'technical-report-deleted');
    }
}
