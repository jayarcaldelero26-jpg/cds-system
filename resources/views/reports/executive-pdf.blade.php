<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        body { font-family: "Times New Roman", serif; font-size: 11pt; color: #17231c; line-height: 1.22; }
        h1 { font-size: 16pt; text-align: center; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 12px 0 5px; border-bottom: 1px solid #78907e; padding-bottom: 3px; }
        p { margin: 4px 0; }
        .meta { text-align: center; margin-bottom: 10px; }
        .summary { width: 100%; border-collapse: collapse; margin: 5px 0 8px; }
        th, td { border: 0.6px solid #87958b; padding: 3px 5px; vertical-align: top; word-wrap: break-word; }
        th { background: #166534; color: white; font-weight: bold; text-align: left; }
        .metric td:first-child { width: 72%; }
        .small { font-size: 10pt; }
        .muted { color: #526158; }
    </style>
</head>
<body>
    <h1>CDS-SMART</h1>
    <h1>{{ strtoupper($report['title']) }}</h1>
    <p class="meta">
        {{ $report['period_label'] }}<br>
        {{ $report['filters']['scope_label'] }}<br>
        Through {{ $report['filters']['as_of_date'] }} (Manila)
    </p>

    <h2>Compliance results</h2>
    <table class="summary metric">
        <thead><tr><th scope="col">Metric</th><th scope="col">Value</th></tr></thead>
        <tbody>
            @foreach ($metrics as $label => $value)
                <tr><td>{{ $label }}</td><td>{{ $value ?? 'Not available' }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted small">Due totals use registered requirements with known deadlines. Receipt counts use dated PENRO receipts through the cutoff.</p>

    <h2>Previous period</h2>
    <p>{{ $comparisonText }}</p>

    <h2>Priority follow-up</h2>
    @if ($report['filters']['status'] !== '')
        <p class="small">Detail status {{ $report['filters']['status'] }}</p>
    @endif
    @if ($report['action_count'] > count($report['actions']))
        <p class="small">Showing {{ count($report['actions']) }} of {{ $report['action_count'] }} items.</p>
    @endif
    <table class="summary small">
        <thead><tr><th scope="col">Report / period</th><th scope="col">PA / Office</th><th scope="col">Deadline</th><th scope="col">Follow-up</th></tr></thead>
        <tbody>
            @forelse ($report['actions'] as $row)
                <tr>
                    <td>{{ $row['report'] }} · {{ $row['period'] }}</td><td>{{ $row['scope'] }}</td>
                    <td>{{ $row['due_date'] ?? 'Unavailable' }}</td>
                    <td>{{ implode(' · ', array_filter([$row['action_office'], $row['next_action']])) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No follow-up items for the applied filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="small muted">Generated {{ $report['generated_at'] }}</p>
</body>
</html>
