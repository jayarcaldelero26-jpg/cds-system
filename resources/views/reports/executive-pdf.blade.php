<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 0.55in; }
        * { box-sizing: border-box; }
        body { font-family: "Times New Roman", serif; font-size: 12pt; color: #17231c; line-height: 1.2; }
        h1 { font-size: 16pt; text-align: center; margin: 0 0 4px; }
        h2 { font-size: 13pt; margin: 14px 0 6px; border-bottom: 1px solid #78907e; padding-bottom: 3px; }
        p { margin: 4px 0; }
        .meta { text-align: center; margin-bottom: 12px; }
        .summary { width: 100%; border-collapse: collapse; margin: 5px 0 9px; }
        th, td { border: 0.6px solid #87958b; padding: 4px 5px; vertical-align: top; word-wrap: break-word; }
        th { background: #166534; color: white; font-weight: bold; }
        .metric td:first-child { width: 72%; font-weight: bold; }
        .small { font-size: 10pt; }
        .muted { color: #526158; }
    </style>
</head>
<body>
    <h1>CDS-SMART</h1>
    <h1>EXECUTIVE REPORT MONITORING SUMMARY</h1>
    <p class="meta">Reporting Year: {{ $report['filters']['year'] ?? '—' }}<br>{{ $report['filters']['scope_label'] ?? 'All authorized reports' }}<br><span class="muted">Generated: {{ $report['generated_at'] ?? '—' }}</span></p>

    <h2>I. Executive Summary</h2>
    <p>{{ $report['interpretation'] ?? 'No Data' }}</p>

    <h2>II. Compliance Overview</h2>
    <table class="summary metric">
        <tr><th>Metric</th><th>Value</th></tr>
        @foreach ([['Expected Reports', data_get($report, 'summary.expected')], ['Submitted Reports', data_get($report, 'summary.submitted')], ['Submission Compliance %', data_get($report, 'summary.compliance_rate')], ['Overdue / Not Submitted', data_get($report, 'summary.overdue')], ['Pending PENRO Receipt', data_get($report, 'summary.pending_receipt')]] as [$label, $value])
            <tr><td>{{ $label }}</td><td>{{ $value === null ? 'Not Available' : $value }}</td></tr>
        @endforeach
    </table>

    @foreach ([['III. Protected Area Performance', 'pa_performance', 'Protected Area'], ['IV. Office Performance', 'office_performance', 'Office'], ['V. Report Family Performance', 'family_performance', 'Report Family']] as [$heading, $key, $first])
        <h2>{{ $heading }}</h2>
        <table class="summary small">
            <tr><th>{{ $first }}</th><th>Expected</th><th>Submitted</th><th>Compliance %</th><th>Overdue</th></tr>
            @forelse ($report[$key] ?? [] as $row)
                <tr><td>{{ $row['label'] ?? '—' }}</td><td>{{ $row['expected'] ?? '—' }}</td><td>{{ $row['submitted'] ?? '—' }}</td><td>{{ $row['compliance_rate'] === null ? '—' : $row['compliance_rate'] }}</td><td>{{ $row['overdue'] ?? '—' }}</td></tr>
            @empty <tr><td colspan="5">No Data</td></tr> @endforelse
        </table>
    @endforeach

    <h2>VI. Timeliness</h2>
    <table class="summary metric"><tr><th>Metric</th><th>Value</th></tr>
        <tr><td>On-time Rated Reports</td><td>{{ data_get($report, 'timeliness.on_time', 0) }}</td></tr>
        <tr><td>Late Rated Reports</td><td>{{ data_get($report, 'timeliness.late', 0) }}</td></tr>
        <tr><td>Rated Records</td><td>{{ data_get($report, 'timeliness.rated', 0) }}</td></tr>
        <tr><td>Average Days Complied</td><td>{{ data_get($report, 'timeliness.average_days_complied') ?? 'Not Available' }}</td></tr>
    </table>

    <h2>VII. Reports Requiring Attention</h2>
    <table class="summary small"><tr><th>Tracking No.</th><th>Report</th><th>PA / Office</th><th>Period</th><th>Status</th><th>Deadline</th><th>Reason</th></tr>
        @forelse ($report['attention'] ?? [] as $row)<tr><td>{{ $row['tracking_number'] ?? '—' }}</td><td>{{ $row['report'] ?? '—' }}</td><td>{{ $row['scope'] ?? '—' }}</td><td>{{ $row['period'] ?? '—' }}</td><td>{{ $row['status'] ?? '—' }}</td><td>{{ $row['deadline'] ?? '—' }}</td><td>{{ $row['reason'] ?? '—' }}</td></tr>
        @empty <tr><td colspan="7">No reports require attention for this scope.</td></tr> @endforelse
    </table>
</body>
</html>
