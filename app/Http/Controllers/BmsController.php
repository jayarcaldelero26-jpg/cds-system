<?php

namespace App\Http\Controllers;

use App\Models\BmsRecord;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DateTime;
use Exception;
use Barryvdh\DomPDF\Facade\Pdf;

class BmsController extends Controller
{
    public function formatStationRangeForAnnex($station)
    {
        if (empty($station)) return null;

        preg_match('/\d+/', $station, $matches);

        if (!empty($matches)) {
            $num = (int)$matches[0];
            $nextNum = $num + 1;
            return "{$num}-{$nextNum}";
        }

        return $station;
    }

    public function index(Request $request)
    {
        $query = BmsRecord::with('protectedArea')
            ->orderBy('monitoring_date', 'desc')
            ->orderBy('station', 'asc');

        if ($request->filled('protected_area_id')) {
            $query->where('protected_area_id', $request->protected_area_id);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('monitoring_date', [$request->start_date, $request->end_date]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('monitoring_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('monitoring_date', '<=', $request->end_date);
        }

        // --- KUHA SA SPATIAL DATA NGA GIPAMATUD-AN NGA DILI MA-NULL ---
        $geoJsonData = null;
        $protectedAreaId = $request->input('protected_area_id');

        $selectedPa = $protectedAreaId
            ? ProtectedArea::find($protectedAreaId)
            : ProtectedArea::first();

        if ($selectedPa && !empty($selectedPa->spatial_data)) {
            $geoJsonData = json_decode($selectedPa->spatial_data, true);
        } else {
            // Fallback: Kung wala sa napili, pangitaa ang bisan asa nga PA nga naay spatial data
            $anyPaWithData = ProtectedArea::whereNotNull('spatial_data')->first();
            if ($anyPaWithData) {
                $geoJsonData = json_decode($anyPaWithData->spatial_data, true);
            }
        }

        return Inertia::render('Bms/Index', [
            'bmsRecords' => $query->get(),
            'protectedAreas' => ProtectedArea::all(),
            'filters' => $request->only(['protected_area_id', 'category', 'start_date', 'end_date']),
            'spatialData' => $geoJsonData, // <-- Gi-match na nato sa 'spatialData' prop sa MapView!
        ]);
    }

    public function semestralReport(Request $request)
    {
        $protectedAreaId = $request->input('protected_area_id');
        $selectedYear = $request->input('year', date('Y'));

        $query = BmsRecord::query();

        if ($protectedAreaId) {
            $query->where('protected_area_id', $protectedAreaId);
        }

        if ($selectedYear) {
            $query->whereYear('monitoring_date', $selectedYear);
        }

        $semestralData = $query->select(
                'protected_area_id',
                'category',
                'species_scientific_name',
                'species_common_name',
                'station',
                DB::raw('YEAR(monitoring_date) as year_recorded'),
                DB::raw('CASE WHEN MONTH(monitoring_date) <= 6 THEN "Sem 1" ELSE "Sem 2" END as semester'),
                DB::raw('SUM(CAST(count AS UNSIGNED)) as total_count')
            )
            ->groupBy(
                'protected_area_id',
                'category',
                'species_scientific_name',
                'species_common_name',
                'station',
                'year_recorded',
                'semester'
            )
            ->orderBy('species_scientific_name', 'asc')
            ->orderBy('station', 'asc')
            ->get();

        return Inertia::render('Bms/SemestralReport', [
            'semestralData' => $semestralData,
            'protectedAreas' => ProtectedArea::all(),
            'filters' => [
                'protected_area_id' => $protectedAreaId,
                'year' => $selectedYear,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'monitoring_date' => 'required|date',
            'station' => 'nullable|string',
            'time' => 'nullable|string',
            'category' => 'nullable|string',
            'taxonomic_group' => 'required|string',
            'species_common_name' => 'nullable|string',
            'species_scientific_name' => 'required|string',
            'count' => 'required|string',
            'observer_name' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'elevation' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'remarks' => 'nullable|string',
            'location' => 'nullable|string',
            'length_of_transect' => 'nullable|string',
            'weather_condition' => 'nullable|string',
            'ecosystem_type' => 'nullable|string',
            'mode_of_observation' => 'nullable|string',
        ]);

        if ($request->hasFile('attachment')) {
            $validated['attachment'] = $request->file('attachment')->store('bms-attachments', 'public');
        }

        BmsRecord::create($validated);

        return redirect()->back()->with('success', 'Field record successfully added!');
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:csv,txt|max:10240',
            'protected_area_id' => 'required|exists:protected_areas,id',
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return redirect()->back()->withErrors(['file' => 'The uploaded file is empty.']);
        }

        $delimiter = ',';
        if (substr_count($lines[0], ';') > substr_count($lines[0], ',')) {
            $delimiter = ';';
        } elseif (substr_count($lines[0], "\t") > substr_count($lines[0], ',')) {
            $delimiter = "\t";
        }

        $header = str_getcsv(array_shift($lines), $delimiter);
        $header = array_map(fn($h) => strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $h))), $header);

        $stationIndex = null;
        $categoryIndex = null;
        $commonNameIndex = null;
        $scientificNameIndex = null;
        $countIndex = null;
        $latIndex = null;
        $lonIndex = null;
        $elevationIndex = null;
        $timeIndex = null;
        $dateIndex = null;
        $remarksIndex = null;

        foreach ($header as $i => $col) {
            if (str_contains($col, 'station')) $stationIndex = $i;
            elseif (str_contains($col, 'type') || str_contains($col, 'category')) $categoryIndex = $i;
            elseif (str_contains($col, 'common')) $commonNameIndex = $i;
            elseif (str_contains($col, 'scientific') || str_contains($col, 'species')) $scientificNameIndex = $i;
            elseif (str_contains($col, 'count') || str_contains($col, 'abundance')) $countIndex = $i;
            elseif (str_contains($col, 'lat')) $latIndex = $i;
            elseif (str_contains($col, 'lon') || str_contains($col, 'long')) $lonIndex = $i;
            elseif (str_contains($col, 'elev')) $elevationIndex = $i;
            elseif (str_contains($col, 'time')) $timeIndex = $i;
            elseif (str_contains($col, 'date')) $dateIndex = $i;
            elseif (str_contains($col, 'remark')) $remarksIndex = $i;
        }

        DB::beginTransaction();

        try {
            foreach ($lines as $line) {
                $row = str_getcsv($line, $delimiter);

                if (count($row) < 2 || empty(array_filter($row, fn($val) => trim($val) !== ''))) {
                    continue;
                }

                $station       = ($stationIndex !== null && isset($row[$stationIndex])) ? trim($row[$stationIndex]) : null;
                $category      = ($categoryIndex !== null && isset($row[$categoryIndex])) ? trim($row[$categoryIndex]) : 'Flora';
                $commonName    = ($commonNameIndex !== null && isset($row[$commonNameIndex])) ? trim($row[$commonNameIndex]) : null;
                $scientificName = ($scientificNameIndex !== null && isset($row[$scientificNameIndex])) ? trim($row[$scientificNameIndex]) : null;
                $count         = ($countIndex !== null && isset($row[$countIndex])) ? trim($row[$countIndex]) : '1';
                $latitude      = ($latIndex !== null && isset($row[$latIndex])) ? trim($row[$latIndex]) : null;
                $longitude     = ($lonIndex !== null && isset($row[$lonIndex])) ? trim($row[$lonIndex]) : null;
                $elevation     = ($elevationIndex !== null && isset($row[$elevationIndex])) ? trim($row[$elevationIndex]) : null;
                $time          = ($timeIndex !== null && isset($row[$timeIndex])) ? trim($row[$timeIndex]) : null;
                $remarks       = ($remarksIndex !== null && isset($row[$remarksIndex])) ? trim($row[$remarksIndex]) : null;
                $rawDate       = ($dateIndex !== null && isset($row[$dateIndex])) ? trim($row[$dateIndex]) : null;

                if (empty($scientificName) && empty($commonName)) {
                    continue;
                }

                $finalScientific = !empty($scientificName) ? $scientificName : ($commonName ?? 'Unnamed Species');
                $finalCommon = !empty($commonName) ? $commonName : '';

                $parsedDate = now()->format('Y-m-d');
                if (!empty($rawDate)) {
                    $formats = [
                        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
                        'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y',
                        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
                        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
                        'Y/m/d', 'm-d-Y'
                    ];

                    $parsedSuccessfully = false;
                    foreach ($formats as $fmt) {
                        $dt = DateTime::createFromFormat($fmt, trim($rawDate));
                        if ($dt !== false) {
                            $parsedDate = $dt->format('Y-m-d');
                            $parsedSuccessfully = true;
                            break;
                        }
                    }

                    if (!$parsedSuccessfully) {
                        try {
                            $normalized = str_replace(['/', '.'], '-', trim($rawDate));
                            $timestamp = strtotime($normalized);
                            if ($timestamp && $timestamp > 0) {
                                $parsedDate = date('Y-m-d', $timestamp);
                            }
                        } catch (Exception $e) {
                            // Keep default
                        }
                    }
                }

                BmsRecord::updateOrCreate(
                    [
                        'protected_area_id'       => $request->protected_area_id,
                        'monitoring_date'         => $parsedDate,
                        'station'                 => $station,
                        'species_scientific_name' => $finalScientific,
                    ],
                    [
                        'category'                => $category,
                        'species_common_name'     => $finalCommon,
                        'count'                   => $count,
                        'latitude'                => $latitude ?? '0',
                        'longitude'               => $longitude ?? '0',
                        'elevation'               => $elevation,
                        'time'                    => $time,
                        'remarks'                 => $remarks,
                        'taxonomic_group'         => 'General',
                        'observer_name'           => 'Excel Import',
                    ]
                );
            }

            DB::commit();
            return redirect()->back()->with('success', 'Data successfully imported from CSV without duplicates!');

        } catch (Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['file' => 'Import failed: ' . $e->getMessage()]);
        }
    }

    public function importGeoJson(Request $request)
    {
        $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'file' => 'required|file|mimes:json,geojson,txt',
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return back()->withErrors(['file' => 'Dili valid nga GeoJSON format ang gi-upload.']);
        }

        $protectedArea = ProtectedArea::findOrFail($request->protected_area_id);
        $protectedArea->spatial_data = $content;
        $protectedArea->save();

        return redirect()->back()->with('success', 'GeoJSON spatial file successfully imported and saved!');
    }

    public function update(Request $request, BmsRecord $bmsRecord)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'monitoring_date' => 'required|date',
            'station' => 'nullable|string',
            'time' => 'nullable|string',
            'category' => 'nullable|string',
            'taxonomic_group' => 'required|string',
            'species_common_name' => 'nullable|string',
            'species_scientific_name' => 'required|string',
            'count' => 'required|string',
            'observer_name' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'elevation' => 'nullable|string',
            'remarks' => 'nullable|string',
            'location' => 'nullable|string',
            'length_of_transect' => 'nullable|string',
            'weather_condition' => 'nullable|string',
            'ecosystem_type' => 'nullable|string',
            'mode_of_observation' => 'nullable|string',
        ]);

        $bmsRecord->update($validated);

        return redirect()->back()->with('success', 'Record updated successfully!');
    }

    public function bulkUpdateHeader(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'location' => 'nullable|string',
            'monitoring_date' => 'nullable|date',
            'time' => 'nullable|string',
            'length_of_transect' => 'nullable|string',
            'latitude' => 'nullable|string',
            'longitude' => 'nullable|string',
            'weather_condition' => 'nullable|string',
            'elevation' => 'nullable|string',
            'ecosystem_type' => 'nullable|string',
            'observer_name' => 'nullable|string',
        ]);

        BmsRecord::whereIn('id', $request->ids)->update([
            'location' => $request->location,
            'monitoring_date' => $request->monitoring_date,
            'time' => $request->time,
            'length_of_transect' => $request->length_of_transect,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'weather_condition' => $request->weather_condition,
            'elevation' => $request->elevation,
            'ecosystem_type' => $request->ecosystem_type,
            'observer_name' => $request->observer_name,
        ]);

        return redirect()->back()->with('success', 'Header details updated successfully.');
    }

    public function exportPdf(Request $request)
    {
        $query = BmsRecord::with('protectedArea')
            ->orderBy('monitoring_date', 'desc')
            ->orderBy('station', 'asc');

        if ($request->filled('protected_area_id')) {
            $query->where('protected_area_id', $request->protected_area_id);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('monitoring_date', [$request->start_date, $request->end_date]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('monitoring_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('monitoring_date', '<=', $request->end_date);
        }

        $bmsRecords = $query->get();
        $protectedArea = $request->filled('protected_area_id') ? ProtectedArea::find($request->protected_area_id) : null;

        $pdf = Pdf::loadView('bms.pdf-annex', [
            'bmsRecords' => $bmsRecords,
            'protectedArea' => $protectedArea,
            'filters' => $request->only(['protected_area_id', 'category', 'start_date', 'end_date']),
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('BMS_Annex_Summary_Report.pdf');
    }

    public function destroy(BmsRecord $bmsRecord)
    {
        $bmsRecord->delete();

        return redirect()->back()->with('success', 'Record deleted successfully!');
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        BmsRecord::whereIn('id', $request->ids)->delete();

        return redirect()->back()->with('success', 'Selected records deleted successfully!');
    }
}
