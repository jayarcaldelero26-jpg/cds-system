<?php

namespace App\Http\Controllers;

use App\Models\BmsRecord;
use App\Models\BmsAnnexHeader;
use App\Models\BmsReportSubmission;
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
            'annexHeaderMetadata' => $this->annexHeaderFor($request),
            'reportSubmissions' => BmsReportSubmission::with('protectedArea:id,name,short_name')
                ->when($request->filled('report_protected_area_id'), fn ($q) => $q->where('protected_area_id', $request->report_protected_area_id))
                ->when($request->filled('report_semester'), fn ($q) => $q->where('semester', $request->report_semester))
                ->latest('id')
                ->paginate(10, ['*'], 'report_page')
                ->withQueryString()
                ->through(function (BmsReportSubmission $submission) {
                    $data = $submission->toArray();
                    $data['mov_url'] = $submission->mov_file_path
                        ? Storage::disk('public')->url($submission->mov_file_path)
                        : null;

                    return $data;
                }),
            'reportFilters' => $request->only(['report_protected_area_id', 'report_semester', 'tracker']),
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
            'file' => 'required|mimes:csv,txt|max:51200',
            'protected_area_id' => 'required|exists:protected_areas,id',
        ], [
            'file.max' => 'The uploaded CSV file must not exceed 50 MB.',
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return redirect()->back()->withErrors(['file' => 'The uploaded file could not be read.']);
        }

        $firstLine = '';
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }

        if ($firstLine === '') {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return redirect()->back()->withErrors(['file' => 'The uploaded file is empty.']);
        }

        $delimiter = ',';
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }

        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($header === false) {
            fclose($handle);
            return redirect()->back()->withErrors(['file' => 'The uploaded file has no header row.']);
        }

        // Normalize BOM-prefixed, spaced, dashed, and underscored headers to one lookup key.
        $normalizeHeader = static function ($header): string {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);
            $header = trim(mb_strtolower($header));

            return preg_replace('/[^\p{L}\p{N}]+/u', '', $header);
        };

        $headerAliases = [
            'station' => ['station', 'stationno', 'stationnumber'],
            'category' => ['category', 'type'],
            'commonName' => ['commonname', 'speciescommonname'],
            'scientificName' => ['scientificname', 'speciesscientificname', 'speciesname'],
            'count' => ['count', 'abundance'],
            'latitude' => ['latitude', 'lat'],
            'longitude' => ['longitude', 'long', 'lon'],
            'elevation' => ['elevation', 'elev'],
            'time' => ['time', 'monitoringtime'],
            'date' => ['date', 'monitoringdate', 'daterecorded'],
            'remarks' => ['remarks', 'remark', 'notes'],
        ];
        $indexes = array_fill_keys(array_keys($headerAliases), null);

        foreach ($header as $i => $column) {
            $normalizedColumn = $normalizeHeader($column);
            foreach ($headerAliases as $field => $aliases) {
                if (in_array($normalizedColumn, $aliases, true)) {
                    $indexes[$field] = $i;
                    break;
                }
            }
        }

        $valueAt = static function (array $row, ?int $index): ?string {
            return $index !== null && array_key_exists($index, $row)
                ? trim((string) $row[$index])
                : null;
        };

        $rowsRead = 0;
        $rowsSkipped = 0;
        $missingSpeciesRows = 0;
        $inserted = 0;
        $updated = 0;

        DB::beginTransaction();

        try {
            // fgetcsv keeps quoted commas (and quoted line breaks) within the same field.
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowsRead++;

                if (count($row) < 2 || empty(array_filter($row, fn($val) => trim($val) !== ''))) {
                    $rowsSkipped++;
                    continue;
                }

                $station = $valueAt($row, $indexes['station']);
                $category = $valueAt($row, $indexes['category']) ?: 'Flora';
                $commonName = $valueAt($row, $indexes['commonName']);
                $scientificName = $valueAt($row, $indexes['scientificName']);
                $count = $valueAt($row, $indexes['count']) ?: '1';
                $latitude = $valueAt($row, $indexes['latitude']);
                $longitude = $valueAt($row, $indexes['longitude']);
                $elevation = $valueAt($row, $indexes['elevation']);
                $time = $valueAt($row, $indexes['time']);
                $remarks = $valueAt($row, $indexes['remarks']);
                $rawDate = $valueAt($row, $indexes['date']);

                if (empty($scientificName) && empty($commonName)) {
                    $rowsSkipped++;
                    $missingSpeciesRows++;
                    continue;
                }

                $finalScientific = !empty($scientificName) ? $scientificName : ($commonName ?? 'Unnamed Species');
                $finalCommon = !empty($commonName) ? $commonName : '';

                $parsedDate = now()->format('Y-m-d');
                $parsedTime = null;
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
                        $dateErrors = DateTime::getLastErrors();
                        if ($dt !== false && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))) {
                            $parsedDate = $dt->format('Y-m-d');
                            if (empty($time) && str_contains($fmt, 'H:i')) {
                                $parsedTime = $dt->format('H:i:s');
                            }
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
                                if (empty($time) && preg_match('/\d{1,2}:\d{2}/', $rawDate)) {
                                    $parsedTime = date('H:i:s', $timestamp);
                                }
                            }
                        } catch (Exception $e) {
                            // Keep default
                        }
                    }
                }

                $record = BmsRecord::updateOrCreate(
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
                        'time'                    => $time ?: $parsedTime,
                        'remarks'                 => $remarks,
                        'taxonomic_group'         => 'General',
                        'observer_name'           => 'Excel Import',
                    ]
                );

                if ($record->wasRecentlyCreated) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }

            fclose($handle);

            if ($inserted + $updated === 0) {
                DB::rollBack();

                return redirect()->back()->withErrors([
                    'file' => "No valid BMS rows were processed. {$rowsRead} rows read; {$rowsSkipped} skipped ({$missingSpeciesRows} missing scientific/common name).",
                ]);
            }

            DB::commit();
            return redirect()->back()->with(
                'success',
                "Import completed: {$inserted} inserted, {$updated} updated, {$rowsSkipped} skipped. ({$rowsRead} rows read; {$missingSpeciesRows} skipped for missing scientific/common name.)"
            );

        } catch (Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
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
            'protected_area_id' => 'nullable|exists:protected_areas,id',
            'category' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'location' => 'nullable|string',
            'date_conducted' => 'nullable|date',
            'start_end_time' => 'nullable|string',
            'start_gps' => 'nullable|string',
            'end_gps' => 'nullable|string',
            'length_of_transect' => 'nullable|string',
            'weather_condition' => 'nullable|string',
            'elevation' => 'nullable|string',
            'ecosystem_type' => 'nullable|string',
            'species_observed' => 'nullable|string',
            'observer' => 'nullable|string',
        ]);

        BmsAnnexHeader::updateOrCreate(
            $request->only(['protected_area_id', 'category', 'start_date', 'end_date']),
            $request->only(['location', 'date_conducted', 'start_end_time', 'start_gps', 'end_gps', 'length_of_transect', 'weather_condition', 'elevation', 'ecosystem_type', 'species_observed', 'observer'])
        );

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
            'annexHeaderMetadata' => $this->annexHeaderFor($request),
            'filters' => $request->only(['protected_area_id', 'category', 'start_date', 'end_date']),
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('BMS_Annex_Summary_Report.pdf');
    }

    private function annexHeaderFor(Request $request): ?BmsAnnexHeader
    {
        return BmsAnnexHeader::query()
            ->where('protected_area_id', $request->input('protected_area_id'))
            ->where('category', $request->input('category'))
            ->where('start_date', $request->input('start_date'))
            ->where('end_date', $request->input('end_date'))
            ->first();
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
