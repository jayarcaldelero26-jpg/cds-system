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

        $graphRange = (int) $request->input('graph_range', 30);
        if (! in_array($graphRange, [7, 30, 90, 365], true)) {
            $graphRange = 30;
        }

        if ($request->filled('graph_start_date') && $request->filled('graph_end_date')) {
            // Explicit Custom Range: use the exact dates selected by the user.
            $chartQuery->whereBetween('start_date', [
                $request->graph_start_date,
                $request->graph_end_date,
            ]);
        } elseif ($request->filled('protected_area_id')) {
            // One specific PA: calculate the quick-range window from that PA's own latest date.
            $latestDate = Aws::whereNotNull('timestamps')
                ->where('protected_area_id', $request->protected_area_id)
                ->max('start_date');

            if ($latestDate) {
                $defaultStart = date(
                    'Y-m-d',
                    strtotime($latestDate . ' -' . ($graphRange - 1) . ' days')
                );

                $chartQuery->whereBetween('start_date', [$defaultStart, $latestDate]);
            }
        } else {
            // All Protected Areas / Compare PAs:
            // Use one common calendar window for every PA so the series share
            // the same X-axis dates. End at the earliest latest-date among PAs.
            $latestDates = Aws::whereNotNull('timestamps')
                ->whereNotNull('protected_area_id')
                ->selectRaw('protected_area_id, MAX(start_date) as latest_date')
                ->groupBy('protected_area_id')
                ->pluck('latest_date');

            if ($latestDates->isNotEmpty()) {
                $commonEndDate = $latestDates->min();

                $commonStartDate = date(
                    'Y-m-d',
                    strtotime($commonEndDate . ' -' . ($graphRange - 1) . ' days')
                );

                $chartQuery->whereBetween('start_date', [$commonStartDate, $commonEndDate]);
            }
        }

        $chartData = $chartQuery
            ->orderBy('start_date', 'asc')
            ->orderBy('protected_area_id', 'asc')
            ->get();

        return Inertia::render('AWS/Aws', [
            'awsRecords'     => $reportsQuery->paginate(15, ['*'], 'reports_page')->withQueryString(),
            'rawRecords'     => $rawQuery->paginate(15, ['*'], 'raw_page')->withQueryString(),
            'chartRecords'   => $chartData,
            'protectedAreas' => ProtectedArea::orderBy('name')->get(),
            'allProtectedAreasMode' => ! $request->filled('protected_area_id'),
            'filters'        => $request->only(['protected_area_id', 'graph_start_date', 'graph_end_date', 'graph_range'])
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

            // Port 1 and Port 2 use duplicate field names in the ZENTRA export.
            // Keep the CSV column positions distinct instead of using array_combine().
            $columnIndex = [];
            foreach ($cleanedHeader as $index => $name) {
                $columnIndex[$name][] = $index;
            }

            $firstIndex = function (array $names) use ($columnIndex): ?int {
                foreach ($names as $name) {
                    if (! empty($columnIndex[$name])) {
                        return $columnIndex[$name][0];
                    }
                }
                return null;
            };

            $secondIndex = function (array $names) use ($columnIndex): ?int {
                foreach ($names as $name) {
                    if (! empty($columnIndex[$name][1])) {
                        return $columnIndex[$name][1];
                    }
                }
                return null;
            };

            // More tolerant header matching for ATMOS 41 / ECRN / TEROS fields.
            // ZENTRA exports may contain unit symbols, extra spaces, or encoding
            // differences in the degree symbol. Matching by semantic phrase avoids
            // silently losing Wind Direction and Air Temperature.
            $findIndexContaining = function (array $phrases, int $occurrence = 0) use ($cleanedHeader): ?int {
                $matches = [];

                foreach ($cleanedHeader as $index => $header) {
                    foreach ($phrases as $phrase) {
                        if (str_contains($header, $phrase)) {
                            $matches[] = $index;
                            break;
                        }
                    }
                }

                return $matches[$occurrence] ?? null;
            };

            $timestampIndex = $firstIndex(['timestamps', 'timestamp', 'date_time', 'datetime', 'date']);

            $port1PrecipIndex = $findIndexContaining(['mm precipitation'], 0);
            $port2PrecipIndex = $findIndexContaining(['mm precipitation'], 1);

            $port1MaxRateIndex = $findIndexContaining(['mm/h max precip rate'], 0);
            $port2MaxRateIndex = $findIndexContaining(['mm/h max precip rate'], 1);

            $windDirectionIndex = $findIndexContaining(['wind direction']);
            $windSpeedIndex = $findIndexContaining(['wind speed']);
            $airTemperatureIndex = $findIndexContaining(['air temperature']);
            $relativeHumidityIndex = $findIndexContaining(['relative humidity']);
            $pressureIndex = $findIndexContaining(['atmospheric pressure']);

            $port3WaterIndex = $findIndexContaining(['water content']);
            $port3SoilTempIndex = $findIndexContaining(['soil temperature']);
            $port3EcIndex = $findIndexContaining(['saturation extract ec']);

            $rowsByDate = [];
            $seenTimestampsByDate = [];

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

                $timestampRaw = $timestampIndex !== null && isset($row[$timestampIndex])
                    ? trim($row[$timestampIndex])
                    : null;

                if (empty($timestampRaw)) continue;

                $timestampParsed = strtotime($timestampRaw);
                if (!$timestampParsed) continue;

                $dateKey = date('Y-m-d', $timestampParsed);

                if (!isset($seenTimestampsByDate[$dateKey])) {
                    $seenTimestampsByDate[$dateKey] = [];
                }

                if (!isset($rowsByDate[$dateKey])) {
                    $rowsByDate[$dateKey] = [
                        'precipitation' => [],
                        'port2_precipitation' => [],
                        'port2_max_precipitation_rate' => [],
                        'port3_water_content' => [],
                        'port3_soil_temperature' => [],
                        'port3_ec' => [],
                        'wind_direction' => [],
                        'wind_speed' => [],
                        'air_temperature' => [],
                        'relative_humidity' => [],
                        'atmospheric_pressure' => [],
                        'observation_count' => 0,
                    ];
                }

                $timestampKey = (string) $timestampParsed;

                if (isset($seenTimestampsByDate[$dateKey][$timestampKey])) {
                    continue;
                }

                $seenTimestampsByDate[$dateKey][$timestampKey] = true;
                $rowsByDate[$dateKey]['observation_count']++;

                $cleanDecimal = function($val) {
                    $val = trim($val ?? '');
                    if ($val === '' || strtoupper($val) === 'N/A' || $val === '—' || $val === '–') {
                        return null;
                    }
                    $val = str_replace([","], "", $val);
                    return is_numeric($val) ? (float) $val : null;
                };

                $appendIndexed = function(array &$bucket, ?int $index) use ($row, $cleanDecimal): void {
                    if ($index === null || ! array_key_exists($index, $row)) {
                        return;
                    }

                    $numericVal = $cleanDecimal($row[$index]);
                    if ($numericVal !== null) {
                        $bucket[] = $numericVal;
                    }
                };

                // Port 1 / ATMOS 41
                $appendIndexed($rowsByDate[$dateKey]['precipitation'], $port1PrecipIndex);
                $appendIndexed($rowsByDate[$dateKey]['wind_direction'], $windDirectionIndex);
                $appendIndexed($rowsByDate[$dateKey]['wind_speed'], $windSpeedIndex);
                $appendIndexed($rowsByDate[$dateKey]['air_temperature'], $airTemperatureIndex);
                $appendIndexed($rowsByDate[$dateKey]['relative_humidity'], $relativeHumidityIndex);
                $appendIndexed($rowsByDate[$dateKey]['atmospheric_pressure'], $pressureIndex);

                // Port 2 / ECRN-100 — hidden rainfall reference.
                $appendIndexed($rowsByDate[$dateKey]['port2_precipitation'], $port2PrecipIndex);
                $appendIndexed($rowsByDate[$dateKey]['port2_max_precipitation_rate'], $port2MaxRateIndex);

                // Port 3 / TEROS 12 — hidden soil-condition context.
                $appendIndexed($rowsByDate[$dateKey]['port3_water_content'], $port3WaterIndex);
                $appendIndexed($rowsByDate[$dateKey]['port3_soil_temperature'], $port3SoilTempIndex);
                $appendIndexed($rowsByDate[$dateKey]['port3_ec'], $port3EcIndex);
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

            // Wind direction is circular data. Example: 359° and 1° average to 0° (North),
            // not 180°. Use circular mean before converting to the compass label.
            $circularMeanDegrees = function(array $degrees): ?float {
                if (empty($degrees)) {
                    return null;
                }

                $sinSum = 0.0;
                $cosSum = 0.0;

                foreach ($degrees as $degree) {
                    $normalized = fmod((float) $degree, 360.0);
                    if ($normalized < 0) {
                        $normalized += 360.0;
                    }

                    $radians = deg2rad($normalized);
                    $sinSum += sin($radians);
                    $cosSum += cos($radians);
                }

                if (abs($sinSum) < 1e-12 && abs($cosSum) < 1e-12) {
                    return null;
                }

                $mean = rad2deg(atan2($sinSum, $cosSum));
                if ($mean < 0) {
                    $mean += 360.0;
                }

                return $mean;
            };

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
                $totalPrecip = !empty($metrics['precipitation'])
                    ? array_sum($metrics['precipitation'])
                    : 0;

                // Port 2: independent precipitation reference. We retain it but
                // do not replace the displayed Port 1 rainfall value.
                $port2Precip = !empty($metrics['port2_precipitation'])
                    ? array_sum($metrics['port2_precipitation'])
                    : null;

                $port2MaxRate = !empty($metrics['port2_max_precipitation_rate'])
                    ? max($metrics['port2_max_precipitation_rate'])
                    : null;

                $avgPort3Water = !empty($metrics['port3_water_content'])
                    ? array_sum($metrics['port3_water_content']) / count($metrics['port3_water_content'])
                    : null;

                $avgPort3SoilTemp = !empty($metrics['port3_soil_temperature'])
                    ? array_sum($metrics['port3_soil_temperature']) / count($metrics['port3_soil_temperature'])
                    : null;

                $avgPort3Ec = !empty($metrics['port3_ec'])
                    ? array_sum($metrics['port3_ec']) / count($metrics['port3_ec'])
                    : null;

                $rainfallDifferenceMm = null;
                $rainfallDifferencePercent = null;
                $crosscheckStatus = 'Unavailable';

                if ($port2Precip !== null) {
                    $rainfallDifferenceMm = abs($totalPrecip - $port2Precip);
                    $referenceBase = max(abs($totalPrecip), abs($port2Precip), 0.01);
                    $rainfallDifferencePercent = ($rainfallDifferenceMm / $referenceBase) * 100;

                    // Internal QA indicator only; no sensor value is overwritten.
                    $crosscheckStatus = $rainfallDifferencePercent <= 20
                        ? 'Generally consistent'
                        : 'Review discrepancy';
                }

                $soilContext = 'Unavailable';

                if ($avgPort3Water !== null) {
                    if ($avgPort3Water >= 35) {
                        $soilContext = 'Higher soil moisture';
                    } elseif ($avgPort3Water <= 20) {
                        $soilContext = 'Lower soil moisture';
                    } else {
                        $soilContext = 'Moderate soil moisture';
                    }
                }

                $avgWindDir  = $circularMeanDegrees($metrics['wind_direction']);
                $avgWindSpd  = !empty($metrics['wind_speed']) ? array_sum($metrics['wind_speed']) / count($metrics['wind_speed']) : null;
                $avgTemp     = !empty($metrics['air_temperature']) ? array_sum($metrics['air_temperature']) / count($metrics['air_temperature']) : null;
                $avgHum      = !empty($metrics['relative_humidity']) ? array_sum($metrics['relative_humidity']) / count($metrics['relative_humidity']) : null;
                $avgPress    = !empty($metrics['atmospheric_pressure']) ? array_sum($metrics['atmospheric_pressure']) / count($metrics['atmospheric_pressure']) : null;

                $windDirectionLabel = $degreesToCompass($avgWindDir);
                $calculatedRemarks = $generateRemarks($totalPrecip, $avgWindSpd, $avgTemp);
                $formattedDate = date('F d, Y', strtotime($date));

                $expectedObservations = 96;
                $observationCount = (int) ($metrics['observation_count'] ?? 0);
                $completenessPercent = min(
                    100,
                    round(($observationCount / $expectedObservations) * 100, 1)
                );

                $awsRecord = Aws::create([
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

                // Hidden Port 2 / Port 3 reference fields.
                $awsRecord->port2_precipitation = $port2Precip !== null ? round($port2Precip, 2) : null;
                $awsRecord->port2_max_precipitation_rate = $port2MaxRate !== null ? round($port2MaxRate, 2) : null;
                $awsRecord->port3_water_content = $avgPort3Water !== null ? round($avgPort3Water, 2) : null;
                $awsRecord->port3_soil_temperature = $avgPort3SoilTemp !== null ? round($avgPort3SoilTemp, 2) : null;
                $awsRecord->port3_ec = $avgPort3Ec !== null ? round($avgPort3Ec, 3) : null;
                $awsRecord->rainfall_difference_mm = $rainfallDifferenceMm !== null ? round($rainfallDifferenceMm, 2) : null;
                $awsRecord->rainfall_difference_percent = $rainfallDifferencePercent !== null ? round($rainfallDifferencePercent, 2) : null;
                $awsRecord->rainfall_crosscheck_days = $port2Precip !== null ? 1 : 0;
                $awsRecord->rainfall_crosscheck_status = $crosscheckStatus;
                $awsRecord->soil_condition_context = $soilContext;

                // Existing QC fields.
                $awsRecord->observation_count = $observationCount;
                $awsRecord->expected_observations = $expectedObservations;
                $awsRecord->data_completeness = $completenessPercent;
                $awsRecord->save();

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
