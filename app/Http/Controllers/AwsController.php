<?php

namespace App\Http\Controllers;

use App\Models\Aws;
use App\Models\ProtectedArea;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AwsController extends Controller
{
    public function index(Request $request)
    {
        // 1. REPORTS QUERY: Kuhaon lang kadtong mga pormal nga report (walay timestamps/raw data flag)
        $reportsQuery = Aws::with('protectedArea')->whereNull('timestamps')->latest();

        if ($request->has('protected_area_id') && $request->protected_area_id) {
            $reportsQuery->where('protected_area_id', $request->protected_area_id);
        }

        // 2. RAW DATA QUERY: Kuhaon lang kadtong mga naay timestamps (mga imported CSV data)
        $rawQuery = Aws::with('protectedArea')->whereNotNull('timestamps')->latest();

        if ($request->has('protected_area_id') && $request->protected_area_id) {
            $rawQuery->where('protected_area_id', $request->protected_area_id);
        }

        // 3. CHART DATA QUERY (Para sa Line Graph nga naay Date Range Filter)
        $chartQuery = Aws::whereNotNull('timestamps');

        if ($request->has('protected_area_id') && $request->protected_area_id) {
            $chartQuery->where('protected_area_id', $request->protected_area_id);
        }

        if ($request->filled('graph_start_date') && $request->filled('graph_end_date')) {
            $chartQuery->whereBetween('start_date', [$request->graph_start_date, $request->graph_end_date]);
        } else {
            // Default: Kuhaon ang pinakabag-o nga 30 ka adlaw aron hapsay tan-awon ang graph
            $chartQuery->orderBy('start_date', 'desc')->limit(30);
        }

        $chartData = $chartQuery->orderBy('start_date', 'asc')->get();

        return Inertia::render('AWS/Aws', [
            'awsRecords'     => $reportsQuery->paginate(15, ['*'], 'reports_page')->withQueryString(),
            'rawRecords'     => $rawQuery->paginate(15, ['*'], 'raw_page')->withQueryString(),
            'chartRecords'   => $chartData, // <--- Data para sa Line Graph
            'protectedAreas' => ProtectedArea::all(),
            'filters'        => $request->only(['protected_area_id', 'graph_start_date', 'graph_end_date'])
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules(fileRequired: true));

        if ($request->hasFile('report_file')) {
            $path = $request->file('report_file')->store('aws_reports', 'public');
            $validated['report_file_path'] = $path;
            $validated['report_file_name'] = $request->file('report_file')->getClientOriginalName();
        }

        // Siguraduhon nga null ang timestamps para malista gyud sa Reports tab ug dili sa Raw Data
        $validated['timestamps'] = null;

        Aws::create($validated);

        return redirect()->route('aws.index')->with('success', 'AWS monitoring report successfully uploaded.');
    }

    public function update(Request $request, Aws $aws)
    {
        $validated = $request->validate($this->validationRules(fileRequired: false));

        if ($request->hasFile('report_file')) {
            $path = $request->file('report_file')->store('aws_reports', 'public');
            $validated['report_file_path'] = $path;
            $validated['report_file_name'] = $request->file('report_file')->getClientOriginalName();

            if ($aws->report_file_path) {
                Storage::disk('public')->delete($aws->report_file_path);
            }
        }
        elseif (
            !$request->hasFile('report_file') &&
            ($request->input('report_file') === null || $request->input('report_file') === 'null' || $request->input('report_file') === '')
        ) {
            if ($aws->report_file_path) {
                Storage::disk('public')->delete($aws->report_file_path);
            }

            $validated['report_file_path'] = null;
            $validated['report_file_name'] = null;
        }

        // Magpabilin nga null ang timestamps para sa manual reports
        $validated['timestamps'] = null;

        $aws->update($validated);

        return redirect()->route('aws.index')->with('success', 'AWS monitoring report successfully updated.');
    }

    public function destroy(Aws $aws)
    {
        if ($aws->report_file_path) {
            Storage::disk('public')->delete($aws->report_file_path);
        }
        $aws->delete();

        return redirect()->route('aws.index')->with('success', 'AWS record successfully deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:aws,id'],
        ]);

        $records = Aws::whereIn('id', $validated['ids'])->get();

        DB::transaction(function () use ($validated) {
            Aws::whereIn('id', $validated['ids'])->delete();
        });

        foreach ($records as $record) {
            if ($record->report_file_path) {
                Storage::disk('public')->delete($record->report_file_path);
            }
        }

        return redirect()->route('aws.index')->with('success', 'Selected AWS records successfully deleted.');
    }

    public function import(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'file'              => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ]);

        try {
            $file = $request->file('file');
            $handle = fopen($file->getRealPath(), 'r');

            $header = null;
            $currentRow = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $currentRow++;
                $loweredRow = array_map(function($h) {
                    return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h)));
                }, $row);

                if (in_array('timestamps', $loweredRow) || in_array('timestamp', $loweredRow)) {
                    $header = $row;
                    fgetcsv($handle);
                    break;
                }

                if ($currentRow > 10) {
                    break;
                }
            }

            if (!$header) {
                fclose($handle);
                return back()->withErrors(['file' => 'No valid timestamp header found inside the CSV file. Please check file format.']);
            }

            $cleanedHeader = array_map(function($h) {
                return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h)));
            }, $header);

            $rowsByDate = [];

            while (($row = fgetcsv($handle)) !== false) {
                if (empty(array_filter($row))) {
                    continue;
                }

                if (count($cleanedHeader) !== count($row)) {
                    if (count($cleanedHeader) > count($row)) {
                        $row = array_pad($row, count($cleanedHeader), null);
                    } else {
                        $row = array_slice($row, 0, count($cleanedHeader));
                    }
                }

                $data = array_combine($cleanedHeader, $row);

                $timestampRaw = null;
                foreach (['timestamps', 'timestamp', 'date_time', 'datetime', 'date'] as $tk) {
                    if (isset($data[$tk]) && !empty(trim($data[$tk]))) {
                        $timestampRaw = trim($data[$tk]);
                        break;
                    }
                }

                if (empty($timestampRaw)) continue;

                $timestampParsed = strtotime($timestampRaw);
                if (!$timestampParsed) continue;

                $dateKey = date('Y-m-d', $timestampParsed);

                $cleanDecimal = function($val) {
                    $val = trim($val ?? '');
                    if ($val === '' || strtoupper($val) === 'N/A' || $val === '—' || $val === '–') {
                        return null;
                    }
                    $val = str_replace([","], "", $val);
                    if (!is_numeric($val)) return null;
                    return (float) $val;
                };

                if (!isset($rowsByDate[$dateKey])) {
                    $rowsByDate[$dateKey] = [
                        'precipitation' => [],
                        'wind_direction' => [],
                        'wind_speed' => [],
                        'air_temperature' => [],
                        'relative_humidity' => [],
                        'atmospheric_pressure' => [],
                    ];
                }

                foreach ($data as $key => $val) {
                    $k = strtolower(trim($key));
                    $numericVal = $cleanDecimal($val);

                    if ($numericVal !== null) {
                        if ($k === 'mm precipitation' || $k === 'precipitation' || $k === 'rain') {
                            $rowsByDate[$dateKey]['precipitation'][] = $numericVal;
                        } elseif (str_contains($k, 'wind direction') || str_contains($k, 'wind_dir')) {
                            $rowsByDate[$dateKey]['wind_direction'][] = $numericVal;
                        } elseif (str_contains($k, 'wind speed') || str_contains($k, 'wind_spd')) {
                            $rowsByDate[$dateKey]['wind_speed'][] = $numericVal;
                        } elseif (str_contains($k, 'air temperature') || str_contains($k, 'temp')) {
                            $rowsByDate[$dateKey]['air_temperature'][] = $numericVal;
                        } elseif (str_contains($k, 'relative humidity') || str_contains($k, 'hum')) {
                            $rowsByDate[$dateKey]['relative_humidity'][] = $numericVal;
                        } elseif (str_contains($k, 'atmospheric pressure') || str_contains($k, 'press')) {
                            $rowsByDate[$dateKey]['atmospheric_pressure'][] = $numericVal;
                        }
                    }
                }
            }
            fclose($handle);

            if (empty($rowsByDate)) {
                return back()->withErrors(['file' => 'No valid timestamp rows found inside the CSV file.']);
            }

            // --- DUPLICATE CHECK ---
            $dates = array_keys($rowsByDate);
            $existingDates = Aws::where('protected_area_id', $request->protected_area_id)
                ->whereIn('start_date', $dates)
                ->whereNotNull('timestamps')
                ->pluck('start_date')
                ->toArray();

            if (!empty($existingDates)) {
                $totalExisting = count($existingDates);
                $sampleDates = array_slice($existingDates, 0, 3);
                $formattedSample = implode(', ', array_map(function($d) {
                    return date('F d, Y', strtotime($d));
                }, $sampleDates));

                $remainingCount = $totalExisting - 3;
                $extraText = $remainingCount > 0 ? " and {$remainingCount} more day(s)" : "";

                return back()->withErrors([
                    'file' => "Warning! There are {$totalExisting} date(s) (e.g., {$formattedSample}{$extraText}) that have already been imported into the database for this Protected Area. Please remove them from your CSV or delete existing records before trying again."
                ]);
            }
            // ------------------------------------

            $degreesToCompass = function($deg) {
                if ($deg === null) return '—';
                $deg = fmod((float)$deg, 360);
                if ($deg < 0) $deg += 360;
                $directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW', 'N'];
                $index = (int) round($deg / 22.5);
                return $directions[$index] ?? 'N';
            };

            $generateRemarks = function($precip, $windSpd, $temp) {
                $remarks = [];

                if ($precip !== null && $precip > 50) {
                    $remarks[] = "Heavy Rainfall Advisory (Total: {$precip}mm)";
                } elseif ($precip !== null && $precip > 15) {
                    $remarks[] = "Moderate Rain Observed";
                }

                if ($windSpd !== null && $windSpd > 10) {
                    $remarks[] = "Strong Wind Alert ({$windSpd} m/s)";
                }

                if ($temp !== null && $temp > 32) {
                    $remarks[] = "High Temperature ({$temp}°C)";
                } elseif ($temp !== null && $temp < 20) {
                    $remarks[] = "Cool Conditions ({$temp}°C)";
                }

                if (empty($remarks)) {
                    return "Normal Weather Conditions";
                }

                return implode(" | ", $remarks);
            };

            DB::beginTransaction();
            $successCount = 0;

            foreach ($rowsByDate as $date => $metrics) {
                $totalPrecip = !empty($metrics['precipitation']) ? array_sum($metrics['precipitation']) : 0;
                $avgWindDir  = !empty($metrics['wind_direction']) ? array_sum($metrics['wind_direction']) / count($metrics['wind_direction']) : null;
                $avgWindSpd  = !empty($metrics['wind_speed']) ? array_sum($metrics['wind_speed']) / count($metrics['wind_speed']) : null;
                $avgTemp     = !empty($metrics['air_temperature']) ? array_sum($metrics['air_temperature']) / count($metrics['air_temperature']) : null;
                $avgHum      = !empty($metrics['relative_humidity']) ? array_sum($metrics['relative_humidity']) / count($metrics['relative_humidity']) : null;
                $avgPress    = !empty($metrics['atmospheric_pressure']) ? array_sum($metrics['atmospheric_pressure']) / count($metrics['atmospheric_pressure']) : null;

                $windDirectionLabel = $degreesToCompass($avgWindDir);
                $calculatedRemarks = $generateRemarks($totalPrecip, $avgWindSpd, $avgTemp);
                $formattedDate = date('F d, Y', strtotime($date));

                Aws::create([
                    'protected_area_id'    => $request->protected_area_id,
                    'station_name'         => 'AWS Weather Station (Zentra Config)',
                    'location'             => 'Protected Area Station',
                    'report_period_type'   => 'Daily',
                    'start_date'           => $date,
                    'end_date'             => $date,
                    'status'               => 'Approve',
                    'timestamps'           => $formattedDate,
                    'atmospheric_pressure' => $avgPress !== null ? round($avgPress, 2) : null,
                    'air_temperature'      => $avgTemp !== null ? round($avgTemp, 2) : null,
                    'relative_humidity'    => $avgHum !== null ? round($avgHum, 2) : null,
                    'precipitation'        => round($totalPrecip, 2),
                    'wind_speed'           => $avgWindSpd !== null ? round($avgWindSpd, 2) : null,
                    'wind_direction'       => $windDirectionLabel,
                    'remarks'              => $calculatedRemarks,
                ]);
                $successCount++;
            }

            DB::commit();
            return redirect()->route('aws.index', ['tab' => 'raw-data'])->with('success', "Successfully imported {$successCount} daily weather records from Zentra file!");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['file' => 'Error importing file: ' . $e->getMessage()]);
        }
    }

    private function validationRules(bool $fileRequired): array
    {
        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'station_name' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'report_period_type' => ['required', 'string', 'in:Monthly,Quarterly,Semestral,Daily'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'string', 'in:Active,Maintenance,Inactive,Approve,Pending,Under Maintenance'],
            'recommendation_remarks' => ['nullable', 'string'],
            'report_file' => [$fileRequired ? 'required' : 'nullable', 'file', 'mimes:xlsx,xls,docx,pdf', 'max:10240'],
        ];
    }
}
