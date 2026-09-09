<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>AWS Monitoring Summary</title>
    <style>
        @page { size: A4 portrait; margin: 0.55in; }
        body { font-family: "Times New Roman", Times, serif; color: #17221b; font-size: 12pt; line-height: 1.1; }
        h1 { margin: 0; text-align: center; font-size: 16pt; letter-spacing: .2px; }
        h2 { margin: 3px 0 10px; text-align: center; font-size: 12pt; font-weight: normal; }
        h3 { margin: 14px 0 6px; color: #14532d; font-size: 12pt; font-weight: bold; page-break-after: avoid; }
        .meta { margin: 0 0 8px; font-size: 12pt; }
        .pa-section { page-break-inside: auto; }
        .pa-section + .pa-section { page-break-before: always; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th { background: #166534; color: #fff; font-weight: bold; font-size: 12pt; }
        th, td { border: 1px solid #82938a; padding: 3px 2px; text-align: center; vertical-align: middle; overflow-wrap: break-word; word-wrap: break-word; font-size: 12pt; }
        tr { page-break-inside: avoid; }
        .monthly th:first-child, .monthly td:first-child { width: 10%; text-align: left; }
        .monthly th:nth-child(2), .monthly td:nth-child(2) { width: 11%; }
        .monthly th:nth-child(3), .monthly td:nth-child(3) { width: 11%; }
        .monthly th:nth-child(4), .monthly td:nth-child(4) { width: 12%; }
        .monthly th:nth-child(5), .monthly td:nth-child(5) { width: 10%; }
        .monthly th:nth-child(6), .monthly td:nth-child(6) { width: 9%; }
        .monthly th:nth-child(7), .monthly td:nth-child(7) { width: 9%; }
        .monthly th:nth-child(8), .monthly td:nth-child(8) { width: 9%; }
        .monthly th:last-child, .monthly td:last-child { width: 19%; text-align: left; }
        .periodic th:first-child, .periodic td:first-child { width: 13%; text-align: left; }
        .periodic th:nth-child(2), .periodic td:nth-child(2) { width: 11%; text-align: left; }
        .periodic th:nth-child(3), .periodic td:nth-child(3) { width: 10%; }
        .periodic th:nth-child(4), .periodic td:nth-child(4) { width: 10%; }
        .periodic th:nth-child(5), .periodic td:nth-child(5) { width: 12%; }
        .periodic th:nth-child(6), .periodic td:nth-child(6) { width: 9%; }
        .periodic th:nth-child(7), .periodic td:nth-child(7) { width: 8%; }
        .periodic th:nth-child(8), .periodic td:nth-child(8) { width: 8%; }
        .periodic th:nth-child(9), .periodic td:nth-child(9) { width: 8%; }
        .periodic th:last-child, .periodic td:last-child { width: 11%; text-align: left; }
        .note { margin-top: 9px; font-size: 12pt; color: #46564c; }
    </style>
</head>
<body>
    <h1>AUTOMATED WEATHER STATION (AWS)</h1>
    <h2>{{ $mode === 'one_month' ? 'Daily Monitoring Summary' : (($mode === 'custom_range' || $mode === 'month') ? 'Monthly Monitoring Summary' : ($mode === 'day' ? 'Daily Monitoring Summary' : 'Monitoring Summary')) }} | {{ $periodLabel }}</h2>
    @if($mode === 'day') <p class="meta"><strong>Reporting Date:</strong> {{ $periodLabel }}</p> @endif
    @if($mode === 'custom_range' || $mode === 'range') <p class="meta"><strong>Reporting Period:</strong> {{ $periodLabel }}</p> @endif
    @if(in_array($mode, ['one_month', 'custom_range', 'month'], true))
        @forelse($rows->groupBy('protected_area_id') as $group)
            <section class="pa-section">
                <h3>{{ $group->first()['protected_area_name'] ?: 'Protected Area' }}</h3>
                <table class="monthly">
                    <thead><tr>
                        <th>{{ $mode === 'one_month' ? 'Reporting Date' : 'Month / Reporting Period' }}</th><th>Average Atmospheric Pressure (kPa)</th>
                        <th>Average Air Temperature (&deg;C)</th><th>Average Vapor Pressure Deficit (kPa)</th>
                        <th>Average Relative Humidity (%)</th><th>Mean Wind Direction (&deg;)</th>
                        <th>Total Precipitation (mm)</th><th>Average Wind Speed (m/s)</th><th>Remarks</th>
                    </tr></thead>
                    <tbody>
                    @foreach($group as $row)
                        <tr><td>{{ $row['period'] }}</td>
                            <td>{{ $row['average_atmospheric_pressure'] ?? "\u{2014}" }}</td><td>{{ $row['average_air_temperature'] ?? "\u{2014}" }}</td>
                            <td>{{ $row['average_vapor_pressure_deficit'] ?? "\u{2014}" }}</td><td>{{ $row['average_relative_humidity'] ?? "\u{2014}" }}</td>
                            <td>{{ $row['mean_wind_direction'] ?? "\u{2014}" }}</td><td>{{ $row['total_precipitation'] ?? "\u{2014}" }}</td>
                            <td>{{ $row['average_wind_speed'] ?? "\u{2014}" }}</td><td>{{ $row['remarks'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @empty
            <p class="meta">No AWS observations found for the selected period.</p>
        @endforelse
    @else
        <table class="periodic">
            <thead><tr><th>Protected Area</th><th>Reporting Period</th><th>Average Atmospheric Pressure (kPa)</th>
                <th>Average Air Temperature (&deg;C)</th><th>Average Vapor Pressure Deficit (kPa)</th>
                <th>Average Relative Humidity (%)</th><th>Mean Wind Direction (&deg;)</th>
                <th>Total Precipitation (mm)</th><th>Average Wind Speed (m/s)</th><th>Remarks</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr><td>{{ $row['protected_area_name'] ?: "\u{2014}" }}</td><td>{{ $row['period'] }}</td>
                    <td>{{ $row['average_atmospheric_pressure'] ?? "\u{2014}" }}</td><td>{{ $row['average_air_temperature'] ?? "\u{2014}" }}</td>
                    <td>{{ $row['average_vapor_pressure_deficit'] ?? "\u{2014}" }}</td><td>{{ $row['average_relative_humidity'] ?? "\u{2014}" }}</td>
                    <td>{{ $row['mean_wind_direction'] ?? "\u{2014}" }}</td><td>{{ $row['total_precipitation'] ?? "\u{2014}" }}</td>
                    <td>{{ $row['average_wind_speed'] ?? "\u{2014}" }}</td><td>{{ $row['remarks'] }}</td></tr>
            @empty
                <tr><td colspan="10">No AWS observations found for the selected period.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
    <p class="note">Vapor pressure deficit uses the existing AWS temperature/relative-humidity derivation. Missing measurements remain blank and are not treated as zero.</p>
</body>
</html>
