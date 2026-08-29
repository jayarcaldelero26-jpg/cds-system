<?php

namespace App\Http\Controllers;

use App\Models\ImeaAssessment;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaFacility;
use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImeaAssessmentController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments) {}

    public function index(Request $request): Response
    {
        $assessments = ImeaAssessment::with('protectedArea')
            ->orderBy('assessment_year', 'desc')
            ->paginate(15)->through(fn (ImeaAssessment $assessment) => $this->assessmentData($assessment));

        $protectedAreaId = $request->input('protected_area_id');

        $facilitiesQuery = ProtectedAreaFacility::with('protectedArea')
            ->orderBy('year_established', 'desc');

        if ($protectedAreaId) {
            $facilitiesQuery->where('protected_area_id', $protectedAreaId);
        }

        $facilities = $facilitiesQuery->paginate(15)->withQueryString();

        return Inertia::render('Imea/Index', [
            'assessments' => $assessments,
            'facilities' => $facilities,
            'protectedAreas' => ProtectedArea::all(['id', 'name']),
            'filters' => [
                'protected_area_id' => $protectedAreaId,
            ],
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

        $assessmentsList = $query->orderBy('assessment_year', 'desc')->get()
            ->map(fn (ImeaAssessment $assessment) => $this->assessmentData($assessment))
            ->values();

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
                $path = $this->attachments->store($file, 'imea-data');
                $attachmentPaths[] = $path;
            }
        }

        try {
            DB::transaction(fn () => ImeaAssessment::create([
                ...collect($validated)->except('attachments')->toArray(),
                'attachments' => $attachmentPaths,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]));
        } catch (\Throwable $exception) {
            foreach ($attachmentPaths as $path) $this->attachments->delete($path);
            throw $exception;
        }

        return to_route('imea.index')->with('success', 'IMEA assessment created successfully.');
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

        $removedPaths = [];
        if ($request->has('removed_attachments')) {
            $removed = $request->input('removed_attachments', []);
            $currentAttachments = array_values(array_filter($currentAttachments, function ($file, $index) use ($removed, &$removedPaths): bool {
                $path = $this->attachmentPath($file);
                $selected = in_array((string) $index, array_map('strval', $removed), true) || in_array($file, $removed, true) || ($path && in_array($path, $removed, true));
                if ($selected && $path) $removedPaths[] = $path;
                return ! $selected;
            }, ARRAY_FILTER_USE_BOTH));
        }

        $newPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $this->attachments->store($file, 'imea-data');
                $newPaths[] = $path;
                $currentAttachments[] = $path;
            }
        }

        try {
            DB::transaction(fn () => $imeaAssessment->update([
                ...collect($validated)->except(['attachments', 'removed_attachments'])->toArray(),
                'attachments' => $currentAttachments,
                'updated_by' => $request->user()->id,
            ]));
        } catch (\Throwable $exception) {
            foreach ($newPaths as $path) $this->attachments->delete($path);
            throw $exception;
        }
        foreach ($removedPaths as $path) $this->attachments->delete($path);

        return to_route('imea.index')->with('success', 'IMEA assessment updated successfully.');
    }

    private function assessmentData(ImeaAssessment $assessment): array
    {
        $data = collect($assessment->toArray())->except(['attachments'])->all();
        $data['attachments'] = collect($assessment->attachments ?? [])
            ->keys()
            ->map(fn (int $index) => $this->attachments->descriptor('imea-data', $assessment, (string) $index))
            ->filter()
            ->values()
            ->all();
        return $data;
    }

    private function attachmentPath(mixed $attachment): ?string
    {
        $path = is_string($attachment) ? $attachment : (is_array($attachment) ? ($attachment['path'] ?? null) : null);
        return is_string($path) && $path !== '' ? $path : null;
    }

    public function destroy(ImeaAssessment $imeaAssessment): RedirectResponse
    {
        $attachments = $imeaAssessment->attachments ?? [];
        $imeaAssessment->delete();
        foreach ($attachments as $attachment) {
            $path = $this->attachmentPath($attachment);
            if ($path) $this->attachments->delete($path);
        }

        return to_route('imea.index')->with('success', 'IMEA assessment deleted successfully.');
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

        return redirect()->back()->with('success', 'Facility inventory record created successfully.');
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

        return redirect()->back()->with('success', 'Facility inventory record updated successfully.');
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

    public function importFacilitiesExcel(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
        ]);

        $protectedArea = ProtectedArea::findOrFail($request->integer('protected_area_id'));
        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return back()->withErrors(['file' => 'The uploaded CSV file could not be read.']);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The uploaded CSV file is empty.']);
        }

        $delimiterCounts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        $delimiter = array_search(max($delimiterCounts), $delimiterCounts, true);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['file' => 'The uploaded CSV file has no header row.']);
        }

        $aliases = [
            'protected_area' => ['protectedarea', 'pa'],
            'inventory_date' => ['inventorydate', 'dateofinventory'],
            'facility_type' => ['facilitystructure', 'facilitytype', 'facility', 'structure'],
            'unit_no' => ['unitno', 'unitnumber', 'units'],
            'year_established' => ['yearestablished', 'yearconstructed'],
            'location_brgy_muni' => ['locationbrgymuni', 'location', 'barangaymunicipality'],
            'management_zone' => ['managementzone', 'zone'],
            'within_easement_zone' => ['withineasementzone', 'easementzone'],
            'status' => ['status', 'condition'],
            'source_of_fund' => ['sourceoffund', 'fundsource'],
            'tenurial_instrument' => ['tenurialinstrument'],
            'recommendations' => ['recommendations', 'recommendation'],
            'remarks' => ['remarks', 'notes'],
        ];

        $indexes = [];
        $unknownHeaders = [];
        $duplicateHeaders = [];

        foreach ($header as $index => $heading) {
            $normalized = $this->normalizeFacilityHeader($heading);
            $field = null;

            foreach ($aliases as $candidate => $candidateAliases) {
                if (in_array($normalized, $candidateAliases, true)) {
                    $field = $candidate;
                    break;
                }
            }

            if ($field === null) {
                $unknownHeaders[] = trim((string) $heading);
            } elseif (array_key_exists($field, $indexes)) {
                $duplicateHeaders[] = trim((string) $heading);
            } else {
                $indexes[$field] = $index;
            }
        }

        $missingHeaders = array_values(array_diff(array_keys($aliases), array_keys($indexes)));

        if ($missingHeaders !== [] || $unknownHeaders !== [] || $duplicateHeaders !== []) {
            fclose($handle);
            $messages = [];
            if ($missingHeaders !== []) {
                $messages[] = 'Missing headers: '.implode(', ', $missingHeaders).'.';
            }
            if ($unknownHeaders !== []) {
                $messages[] = 'Unknown headers: '.implode(', ', $unknownHeaders).'.';
            }
            if ($duplicateHeaders !== []) {
                $messages[] = 'Duplicated headers: '.implode(', ', $duplicateHeaders).'.';
            }

            return back()->withErrors(['file' => implode(' ', $messages)]);
        }

        $existingHashes = ProtectedAreaFacility::where('protected_area_id', $protectedArea->id)
            ->get()
            ->mapWithKeys(fn (ProtectedAreaFacility $facility) => [$this->facilityImportHash($facility->toArray()) => true])
            ->all();
        $seenHashes = $existingHashes;
        $validRows = [];
        $rowErrors = [];
        $duplicateCount = 0;
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rowNumber++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $value = fn (string $field) => $this->nullableCsvValue($row[$indexes[$field]] ?? null);
            $rowProtectedArea = $value('protected_area');
            $payload = [
                'protected_area_id' => $protectedArea->id,
                'inventory_date' => $value('inventory_date'),
                'facility_type' => $value('facility_type'),
                'unit_no' => $value('unit_no'),
                'year_established' => $value('year_established'),
                'location_brgy_muni' => $value('location_brgy_muni'),
                'management_zone' => $value('management_zone'),
                'within_easement_zone' => $value('within_easement_zone'),
                'status' => $value('status'),
                'source_of_fund' => $value('source_of_fund'),
                'tenurial_instrument' => $value('tenurial_instrument'),
                'recommendations' => $value('recommendations'),
                'remarks' => $value('remarks'),
            ];

            $validator = Validator::make($payload, [
                'protected_area_id' => ['required', 'exists:protected_areas,id'],
                'inventory_date' => ['required', 'string', 'max:255', 'regex:/^(?:\d{4}|\d{4}-\d{2}-\d{2}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4})$/i'],
                'facility_type' => ['required', 'string', 'max:255'],
                'unit_no' => ['required', 'integer', 'min:1'],
                'year_established' => ['nullable', 'integer', 'digits:4', 'between:1800,'.(date('Y') + 1)],
                'location_brgy_muni' => ['nullable', 'string', 'max:255'],
                'management_zone' => ['nullable', 'in:MUZ,SPZ'],
                'within_easement_zone' => ['nullable', 'in:Yes,No'],
                'status' => ['required', 'in:Functional,Under Renovation,Under Construction,Dilapidated,Abandoned'],
                'source_of_fund' => ['nullable', 'string', 'max:255'],
                'tenurial_instrument' => ['nullable', 'string', 'max:255'],
                'recommendations' => ['nullable', 'string'],
                'remarks' => ['nullable', 'string'],
            ]);

            $errors = $validator->errors()->all();
            if ($rowProtectedArea === null || mb_strtolower($rowProtectedArea) !== mb_strtolower($protectedArea->name)) {
                $errors[] = "The protected area must match the selected protected area ({$protectedArea->name}).";
            }

            if ($errors !== []) {
                $rowErrors[] = "Row {$rowNumber}: ".implode('; ', $errors);
                continue;
            }

            $payload['unit_no'] = (int) $payload['unit_no'];
            $payload['year_established'] = $payload['year_established'] !== null ? (int) $payload['year_established'] : null;
            $hash = $this->facilityImportHash($payload);

            if (isset($seenHashes[$hash])) {
                $duplicateCount++;
                continue;
            }

            $seenHashes[$hash] = true;
            $validRows[] = $payload;
        }

        fclose($handle);

        if ($rowErrors !== []) {
            return back()->withErrors([
                'file' => 'Import rejected; no rows were written. '.implode(' ', array_slice($rowErrors, 0, 20)),
            ]);
        }

        DB::transaction(function () use ($validRows): void {
            foreach ($validRows as $payload) {
                ProtectedAreaFacility::create($payload);
            }
        });

        return back()->with(
            'success',
            sprintf('Facility import complete: %d imported, %d exact duplicates skipped, 0 failed.', count($validRows), $duplicateCount)
        );
    }

    // Bulk Delete Method
    public function bulkDeleteFacilities(Request $request): RedirectResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:protected_area_facilities,id'],
        ]);

        $facilities = ProtectedAreaFacility::whereIn('id', $request->input('ids'))->get();

        foreach ($facilities as $facility) {
            abort_unless($request->user()->can('imea.delete'), 403);
        }

        DB::transaction(fn () => ProtectedAreaFacility::whereKey($facilities->modelKeys())->delete());

        return redirect()->back()->with('success', 'Selected facility inventory records deleted successfully.');
    }

    private function normalizeFacilityHeader(mixed $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);

        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($header)));
    }

    private function nullableCsvValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || in_array(mb_strtolower($value), ['n/a', 'na', 'null', '-', '—'], true)
            ? null
            : $value;
    }

    private function facilityImportHash(array $data): string
    {
        $fields = [
            'protected_area_id',
            'inventory_date',
            'facility_type',
            'unit_no',
            'year_established',
            'location_brgy_muni',
            'management_zone',
            'within_easement_zone',
            'status',
            'source_of_fund',
            'tenurial_instrument',
            'recommendations',
            'remarks',
        ];

        $values = array_map(fn (string $field) => $data[$field] ?? null, $fields);

        return hash('sha256', json_encode(array_map(
            fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value,
            $values
        ), JSON_THROW_ON_ERROR));
    }

    public function destroyFacility($id): RedirectResponse
    {
        $facility = ProtectedAreaFacility::findOrFail($id);
        $facility->delete();

        return redirect()->back()->with('success', 'Facility inventory record deleted successfully.');
    }
}
