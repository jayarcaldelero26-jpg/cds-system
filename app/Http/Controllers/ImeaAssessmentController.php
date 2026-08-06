<?php

namespace App\Http\Controllers;

use App\Models\ImeaAssessment;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaFacility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImeaAssessmentController extends Controller
{
    public function index(Request $request): Response
    {
        $assessments = ImeaAssessment::with('protectedArea')
            ->orderBy('assessment_year', 'desc')
            ->paginate(15);

        $facilities = ProtectedAreaFacility::with('protectedArea')
            ->orderBy('year_established', 'desc')
            ->paginate(15);

        return Inertia::render('Imea/Index', [
            'assessments' => $assessments,
            'facilities' => $facilities,
            'protectedAreas' => ProtectedArea::all(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Imea/Create', [
            'protectedAreas' => ProtectedArea::all(['id', 'name']),
        ]);
    }

    public function report(Request $request): Response
    {
        $year = $request->input('year');
        $period = $request->input('period');
        $protectedAreaId = $request->input('protected_area_id');

        $query = ImeaAssessment::with('protectedArea');

        if ($year) {
            $query->where('assessment_year', $year);
        }
        if ($period) {
            $query->where('assessment_period', $period);
        }
        if ($protectedAreaId) {
            $query->where('protected_area_id', $protectedAreaId);
        }

        $totalVisitors = (clone $query)->sum('visitor_arrivals');
        $totalWaste = (clone $query)->sum('solid_waste_generation_kg');
        $avgSatisfaction = (clone $query)->avg('visitor_satisfaction_rate');

        $assessmentsList = $query->orderBy('assessment_year', 'desc')->get();

        $availableYears = ImeaAssessment::distinct()->orderBy('assessment_year', 'desc')->pluck('assessment_year');
        $protectedAreas = ProtectedArea::all();

        return Inertia::render('Imea/Report', [
            'totalVisitors' => $totalVisitors,
            'totalWaste' => $totalWaste,
            'avgSatisfaction' => $avgSatisfaction ? round($avgSatisfaction, 2) : 0,
            'assessmentsList' => $assessmentsList,
            'protectedAreas' => $protectedAreas,
            'availableYears' => $availableYears,
            'filters' => [
                'year' => $year,
                'period' => $period,
                'protected_area_id' => $protectedAreaId,
            ],
        ]);
    }

    // ========================================================
    // REPORT PARA SA FACILITIES & INFRASTRUCTURES (GI-UPDATE)
    // ========================================================
    public function facilitiesReport(Request $request): Response
    {
        $protectedAreaId = $request->input('protected_area_id');
        $zone = $request->input('zone');
        $inventoryDate = $request->input('inventory_date');

        $query = ProtectedAreaFacility::with('protectedArea');

        if ($protectedAreaId) {
            $query->where('protected_area_id', $protectedAreaId);
        }
        if ($zone) {
            $query->where('management_zone', $zone);
        }
        if ($inventoryDate) {
            $query->where('inventory_date', $inventoryDate);
        }

        $totalFacilities = (clone $query)->count();
        $muzCount = (clone $query)->where('management_zone', 'MUZ')->count();
        $spzCount = (clone $query)->where('management_zone', 'SPZ')->count();
        $newStructuresCount = (clone $query)->where('year_established', '>', 2022)->count();

        $facilitiesList = $query->orderBy('year_established', 'desc')->get();
        $protectedAreas = ProtectedArea::all();

        // Kuhaon ang tanang unique inventory dates para sa dropdown filter
        $inventoryDates = ProtectedAreaFacility::whereNotNull('inventory_date')
            ->distinct()
            ->orderBy('inventory_date', 'desc')
            ->pluck('inventory_date');

        return Inertia::render('Imea/FacilitiesReport', [
            'totalFacilities' => $totalFacilities,
            'muzCount' => $muzCount,
            'spzCount' => $spzCount,
            'newStructuresCount' => $newStructuresCount,
            'facilitiesList' => $facilitiesList,
            'protectedAreas' => $protectedAreas,
            'inventoryDates' => $inventoryDates,
            'filters' => [
                'protected_area_id' => $protectedAreaId,
                'zone' => $zone,
                'inventory_date' => $inventoryDate,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'pamo_name' => ['required', 'string', 'max:255'],
            'assessment_year' => ['required', 'integer'],
            'assessment_period' => ['required', 'string', 'max:255'],
            'visitor_arrivals' => ['nullable', 'numeric'],
            'trail_condition' => ['nullable', 'string'],
            'solid_waste_generation_kg' => ['nullable', 'numeric'],
            'wildlife_disturbance' => ['nullable', 'string'],
            'vegetation_damage' => ['nullable', 'string'],
            'water_quality' => ['nullable', 'string'],
            'carrying_capacity_compliance' => ['boolean'],
            'community_benefits_income' => ['nullable', 'numeric'],
            'visitor_satisfaction_rate' => ['nullable', 'numeric'],
            'biodiversity_impact_notes' => ['nullable', 'string'],
            'environment_impact_notes' => ['nullable', 'string'],
            'social_cultural_impact_notes' => ['nullable', 'string'],
            'economic_impact_notes' => ['nullable', 'string'],
            'general_remarks' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('imea-attachments', 'public');
                $attachmentPaths[] = $path;
            }
        }

        ImeaAssessment::create([
            ...collect($validated)->except('attachments')->toArray(),
            'attachments' => $attachmentPaths,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return to_route('imea.index')->with('status', 'imea-assessment-created');
    }

    public function update(Request $request, ImeaAssessment $imeaAssessment)
    {
        $validated = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'pamo_name' => ['required', 'string', 'max:255'],
            'assessment_year' => ['required', 'integer'],
            'assessment_period' => ['required', 'string', 'max:255'],
            'visitor_arrivals' => ['nullable', 'numeric'],
            'trail_condition' => ['nullable', 'string'],
            'solid_waste_generation_kg' => ['nullable', 'numeric'],
            'wildlife_disturbance' => ['nullable', 'string'],
            'vegetation_damage' => ['nullable', 'string'],
            'water_quality' => ['nullable', 'string'],
            'carrying_capacity_compliance' => ['boolean'],
            'community_benefits_income' => ['nullable', 'numeric'],
            'visitor_satisfaction_rate' => ['nullable', 'numeric'],
            'biodiversity_impact_notes' => ['nullable', 'string'],
            'environment_impact_notes' => ['nullable', 'string'],
            'social_cultural_impact_notes' => ['nullable', 'string'],
            'economic_impact_notes' => ['nullable', 'string'],
            'general_remarks' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
            'removed_attachments' => ['nullable', 'array'],
        ]);

        $currentAttachments = $imeaAssessment->attachments ?? [];
        if (is_string($currentAttachments)) {
            $currentAttachments = json_decode($currentAttachments, true) ?? [];
        }

        if ($request->has('removed_attachments')) {
            $removed = $request->input('removed_attachments', []);
            $currentAttachments = array_values(array_filter($currentAttachments, function ($file) use ($removed) {
                return !in_array($file, $removed);
            }));
            foreach ($removed as $remFile) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($remFile);
            }
        }

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('imea-attachments', 'public');
                $currentAttachments[] = $path;
            }
        }

        $imeaAssessment->update([
            ...collect($validated)->except(['attachments', 'removed_attachments'])->toArray(),
            'attachments' => $currentAttachments,
            'updated_by' => $request->user()->id,
        ]);

        return to_route('imea.index');
    }

    public function destroy($id)
    {
        $imea = ImeaAssessment::findOrFail($id);
        $imea->delete();

        return to_route('imea.index');
    }

    // ========================================================
    // MGA METHODS PARA SA FACILITIES & INFRASTRUCTURES (GI-UPDATE)
    // ========================================================
    public function storeFacility(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'inventory_date' => 'nullable|string|max:255', // <--- Gi-apil nato diri
            'facility_type' => 'required|string|max:255',
            'unit_no' => 'required|integer|min:1',
            'year_established' => 'nullable|digits:4',
            'location_brgy_muni' => 'nullable|string',
            'management_zone' => 'nullable|string',
            'within_easement_zone' => 'nullable|string',
            'coordinates' => 'nullable|string',
            'source_of_fund' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'required|string',
            'typhoon_affected' => 'nullable|string',
            'tenurial_instrument' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        ProtectedAreaFacility::create($validated);

        return redirect()->back()->with('status', 'facility-created');
    }

    public function updateFacility(Request $request, $id): RedirectResponse
    {
        $facility = ProtectedAreaFacility::findOrFail($id);

        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'inventory_date' => 'nullable|string|max:255', // <--- Gi-apil nato diri
            'facility_type' => 'required|string|max:255',
            'unit_no' => 'required|integer|min:1',
            'year_established' => 'nullable|digits:4',
            'location_brgy_muni' => 'nullable|string',
            'management_zone' => 'nullable|string',
            'within_easement_zone' => 'nullable|string',
            'coordinates' => 'nullable|string',
            'source_of_fund' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'required|string',
            'typhoon_affected' => 'nullable|string',
            'tenurial_instrument' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $facility->update($validated);

        return redirect()->back()->with('status', 'facility-updated');
    }

    public function exportFacilitiesExcel(Request $request)
    {
        $protectedAreaId = $request->input('protected_area_id');
        $zone = $request->input('zone');
        $inventoryDate = $request->input('inventory_date');

        $query = ProtectedAreaFacility::with('protectedArea');

        if ($protectedAreaId) {
            $query->where('protected_area_id', $protectedAreaId);
        }
        if ($zone) {
            $query->where('management_zone', $zone);
        }
        if ($inventoryDate) {
            $query->where('inventory_date', $inventoryDate);
        }

        $facilities = $query->orderBy('year_established', 'desc')->get();

        $filename = "facilities_inventory_" . date('Y-m-d') . ".csv";

        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $callback = function() use ($facilities) {
            $file = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($file, [
                'Protected Area',
                'Inventory Date',
                'Facility / Structure',
                'Unit No.',
                'Year Established',
                'Location (Brgy/Muni)',
                'Management Zone',
                'Within Easement Zone',
                'Status',
                'Source of Fund',
                'Tenurial Instrument',
                'Recommendations',
                'Remarks'
            ]);

            // CSV Rows
            foreach ($facilities as $row) {
                fputcsv($file, [
                    $row->protected_area?->name ?? 'N/A',
                    $row->inventory_date ?? '—',
                    $row->facility_type,
                    $row->unit_no,
                    $row->year_established ?? '—',
                    $row->location_brgy_muni ?? '—',
                    $row->management_zone,
                    $row->within_easement_zone,
                    $row->status,
                    $row->source_of_fund ?? '—',
                    $row->tenurial_instrument ?? '—',
                    $row->recommendations ?? '—',
                    $row->remarks ?? '—',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importFacilitiesExcel(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
        ]);

        $paId = $request->input('protected_area_id');
        $file = $request->file('file');
        $path = $file->getRealPath();

        $rows = array_map('str_getcsv', file($path));

        foreach ($rows as $index => $row) {
            // Laktawan ang unang 4 ka linya (mga titulo, headers, etc.)
            if ($index < 4) continue;

            // Siguruha nga naa bay facility type o bisan unay sulod
            $facilityType = trim($row[1] ?? '');
            if (empty($facilityType)) continue;

            \App\Models\ProtectedAreaFacility::create([
                'protected_area_id' => $paId,
                'inventory_date' => trim($row[1] ?? 'July 2022'), // Pwede sad i-adjust base sa column index sa imong CSV
                'facility_type' => $facilityType,
                'unit_no' => is_numeric($row[2] ?? null) ? (int)$row[2] : 1,
                'year_established' => !empty($row[3] ?? null) && is_numeric($row[3]) ? (int)$row[3] : null,
                'location_brgy_muni' => trim($row[4] ?? ''),
                'management_zone' => trim($row[5] ?? 'MUZ'),
                'within_easement_zone' => trim($row[6] ?? 'No'),
                'coordinates' => trim($row[7] ?? ''),
                'source_of_fund' => trim($row[8] ?? ''),
                'description' => trim($row[9] ?? ''),
                'status' => trim($row[10] ?? 'Functional'),
                'typhoon_affected' => trim($row[11] ?? 'No'),
                'tenurial_instrument' => trim($row[12] ?? ''),
                'recommendations' => trim($row[13] ?? ''),
                'remarks' => trim($row[14] ?? ''),
            ]);
        }

        return redirect()->back()->with('status', 'facility-imported');
    }

    // Bulk Delete Method
    public function bulkDeleteFacilities(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:protected_area_facilities,id'],
        ]);

        \App\Models\ProtectedAreaFacility::whereIn('id', $request->input('ids'))->delete();

        return redirect()->back()->with('status', 'facility-deleted');
    }

    public function destroyFacility($id): RedirectResponse
    {
        $facility = ProtectedAreaFacility::findOrFail($id);
        $facility->delete();

        return redirect()->back()->with('status', 'facility-deleted');
    }
}
