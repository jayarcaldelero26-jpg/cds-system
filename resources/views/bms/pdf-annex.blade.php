<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>BMS Annex Summary Report</title>
    <style>
        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            color: black;
            margin: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid black;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .title {
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            text-align: center;
        }
        th, td {
            border: 1px solid black;
            padding: 6px;
            font-size: 11pt;
        }
        th {
            background-color: #f3f4f6;
        }
        .text-left { text-align: left; padding-left: 8px; }
        .details-grid {
            margin-bottom: 15px;
            font-size: 11pt;
        }
        .details-row {
            margin-bottom: 4px;
        }
        .label {
            font-weight: bold;
            display: inline-block;
            width: 160px;
        }
    </style>
</head>
<body onload="window.print()">

    <div class="header">
        <p style="font-size: 10pt; font-weight: bold; margin: 0;">REPUBLIC OF THE PHILIPPINES</p>
        <p style="font-size: 11pt; font-weight: bold; color: #1e3a8a; margin: 2px 0;">Department of Environment and Natural Resources</p>
        <p style="font-size: 11pt; font-weight: bold; color: #166534; margin: 0;">PROVINCIAL ENVIRONMENT AND NATURAL RESOURCES OFFICE</p>
        <p style="font-size: 9pt; margin: 2px 0;">GOVERNMENT CENTER, DAHICAN, CITY OF MATI</p>
    </div>

    <div class="title">
        <div style="font-size: 11pt; font-weight: bold; margin-bottom: 4px;">CONSERVATION AND DEVELOPMENT DIVISION</div>
        <div style="font-size: 12pt; font-weight: bold;">
            {{ ($filters['category'] ?? '') === 'Fauna' ? 'ANNEX 1-A.2 – SUMMARY OF TRANSECT DATA (FAUNA)' : 'ANNEX 1-A.1 – SUMMARY OF TRANSECT DATA (FLORA)' }}
        </div>
    </div>

    <div class="details-grid">
        <div class="details-row"><span class="label">Protected Area</span>: {{ $protectedArea->name ?? 'All Protected Areas' }}</div>
        <div class="details-row"><span class="label">Location</span>: {{ $bmsRecords[0]->location ?? 'N/A' }}</div>
        <div class="details-row"><span class="label">Date Conducted</span>: {{ $bmsRecords[0]->monitoring_date ?? 'N/A' }}</div>
        <div class="details-row"><span class="label">Observer</span>: {{ $bmsRecords[0]->observer_name ?? 'N/A' }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Station / Meters</th>
                <th>Time of Arrival</th>
                <th>Species Observed (Local / Scientific Name)</th>
                <th>Count</th>
                <th>Mode</th>
            </tr>
        </thead>
        <tbody>
            @forelse($bmsRecords as $record)
                <tr>
                    <td><strong>{{ $record->station }}</strong></td>
                    <td>{{ $record->time ?? '-' }}</td>
                    <td class="text-left">
                        {{ $record->species_common_name }}
                        @if($record->species_scientific_name)
                            <span style="font-style: italic;">({{ $record->species_scientific_name }})</span>
                        @endif
                    </td>
                    <td>{{ $record->count }}</td>
                    <td>{{ $record->mode_of_observation ?? 'Seen' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No records found for this report.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
