<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>{{ $settings['email_subject'] ?? $settings['subject'] }}</title>
<style>
    @media only screen and (max-width: 600px) {
        .memo-shell { padding: 22px 10px !important; }
        .memo-panel { width: 100% !important; }
        .memo-content { padding: 28px 18px 22px !important; }
        .memo-table th, .memo-table td { padding: 7px 6px !important; }
        .memo-table th:first-child { padding-left: 2px !important; padding-right: 2px !important; white-space: nowrap !important; }
        .memo-table th:last-child { padding-left: 2px !important; padding-right: 2px !important; font-size: 10px !important; }
        .memo-table { font-size: 11px !important; line-height: 16px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .memo-body { background: #111827 !important; color: #f3f4f6 !important; }
        .memo-panel { background: #1f2937 !important; border-color: #4b5563 !important; }
        .memo-text { color: #f3f4f6 !important; }
        .memo-muted { color: #d1d5db !important; }
        .memo-group { color: #93c5fd !important; }
        .memo-table th { background: #24445a !important; border-color: #5b8aaa !important; color: #eff6ff !important; }
        .memo-table td { border-color: #4b5563 !important; color: #f3f4f6 !important; }
        .memo-table td span { color: #d1d5db !important; }
        .memo-footer { border-color: #4b5563 !important; }
    }
</style></head>
<body class="memo-body" style="margin:0; padding:0; background:#f3f6f8; color:#1f2937; font-family:Arial, Helvetica, sans-serif; color-scheme:light dark;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="memo-shell" style="background:#f3f6f8; padding:24px 12px;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="memo-panel" style="max-width:760px; background:#fffdf7; border:1px solid #d7dee5;"><tr><td class="memo-content memo-text" style="padding:36px 34px 28px; color:#1f2937;">
<div style="text-align:center; font-size:21px; line-height:28px; font-weight:700; letter-spacing:1px; color:#111827; margin-bottom:30px;">MEMORANDUM</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-size:14px; line-height:21px; margin-bottom:22px;">
<tr><td style="width:92px; font-weight:700; vertical-align:top;">TO:</td><td>{{ $recipient['name'] ?? 'The OIC, PASu' }}</td></tr>
@php($attentionLine = $recipient['attention_line'] ?? null)
@if (filled($attentionLine))<tr><td style="font-weight:700; vertical-align:top;">ATTENTION:</td><td>{{ $attentionLine }}</td></tr>@endif
<tr><td style="font-weight:700; vertical-align:top;">FROM:</td><td>{{ $settings['from_line'] ?? $settings['from'] }}</td></tr>
<tr><td style="font-weight:700; vertical-align:top;">SUBJECT:</td><td style="font-weight:700;">{{ $settings['memorandum_subject'] ?? $settings['subject'] }}</td></tr>
</table>
<p style="font-size:14px; line-height:22px; margin:0 0 16px;">{!! nl2br(e($settings['introductory_text'])) !!}</p>
@php($memoReports = collect($groups)->flatMap(fn ($group) => $group['reports'] ?? []))
@php($hasMissingMov = $memoReports->contains(fn ($report) => ($report['compliance_issue'] ?? null) === 'MOV Not Yet Submitted'))
<p style="font-size:14px; line-height:22px; margin:0 0 18px; font-weight:700;">{{ $hasMissingMov ? 'Below is/are the pending report(s) and supporting document requirement(s) that require immediate action:' : 'Below is/are the pending report(s) that require immediate submission:' }}</p>
@foreach ($groups as $group)
<div class="memo-group" style="margin:22px 0 8px; color:#155e9b; font-size:15px; line-height:22px; font-weight:700;">{{ $group['protected_area_name'] }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="memo-table" style="width:100%; table-layout:fixed; border-collapse:collapse; font-size:12px; line-height:18px; overflow-wrap:anywhere;"><colgroup><col style="width:7%;"><col style="width:55%;"><col style="width:23%;"><col style="width:15%;"></colgroup><thead><tr>
<th align="left" style="border:1px solid #b8d5eb; background:#dceef9; color:#123047; padding:8px 6px; width:7%; word-break:normal; overflow-wrap:normal; white-space:nowrap;">No.</th><th align="left" style="border:1px solid #b8d5eb; background:#dceef9; color:#123047; padding:8px 6px; width:55%; word-break:normal; overflow-wrap:normal; white-space:normal;">Activity (Document Type)</th><th align="left" style="border:1px solid #b8d5eb; background:#dceef9; color:#123047; padding:8px 6px; width:23%; word-break:normal; overflow-wrap:normal; white-space:normal;">Deadline</th><th align="center" style="border:1px solid #b8d5eb; background:#dceef9; color:#123047; padding:8px 6px; width:15%; word-break:normal; overflow-wrap:normal; white-space:normal;">Days Overdue</th>
</tr></thead><tbody>
@foreach ($group['reports'] as $index => $report)
<tr>
<td style="border:1px solid #d7dee5; padding:8px 6px; vertical-align:top; overflow-wrap:anywhere;">{{ $index + 1 }}</td><td style="border:1px solid #d7dee5; padding:8px 6px; vertical-align:top; overflow-wrap:anywhere;"><strong>{{ ($report['compliance_issue'] ?? null) === 'MOV Not Yet Submitted' ? 'MOV NOT YET SUBMITTED: ' : '' }}{{ $report['activity'] }}</strong><br><span class="memo-muted" style="color:#4b5563;">{{ $report['document_type'] }} &middot; {{ $report['module'] }}</span></td><td style="border:1px solid #d7dee5; padding:8px 6px; vertical-align:top; overflow-wrap:anywhere;">{{ \Carbon\Carbon::parse($report['deadline'])->locale('en')->isoFormat('dddd, MMMM D, YYYY') }}</td><td align="center" style="border:1px solid #d7dee5; padding:8px 6px; vertical-align:top; color:#b42318; font-weight:700; overflow-wrap:anywhere;">{{ $report['days_overdue'] }}</td>
</tr>
@endforeach
</tbody></table>
@endforeach
<p style="font-size:14px; line-height:22px; margin:24px 0 10px;">{!! nl2br(e($settings['compliance_warning_text'])) !!}</p>
<p style="font-size:14px; line-height:22px; margin:0 0 30px; font-weight:700;">{!! nl2br(e($settings['strict_compliance_text'])) !!}</p>
<?php
    $normaliseOffice = static fn (mixed $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    $officeName = (string) ($settings['office_name'] ?? '');
    $officeAddress = (string) ($settings['office_address'] ?? '');
?>
<div style="font-size:14px; line-height:21px; margin:0 0 30px;"><div style="font-weight:700;">(Sgd.) {{ $settings['signatory_name'] }}</div><div>{{ $settings['signatory_position'] }}</div><div>{{ $officeName }}</div>@if ($officeAddress !== '' && $normaliseOffice($officeAddress) !== $normaliseOffice($officeName))<div>{{ $officeAddress }}</div>@endif</div>
<div class="memo-footer" style="border-top:1px solid #e5e7eb; padding-top:16px; color:#b42318; font-size:11px; line-height:17px; font-style:italic;"><div>{!! nl2br(e($settings['system_generated_footer_text'])) !!}</div><div style="margin-top:7px;">{{ $settings['do_not_reply_text'] }}</div><div style="margin-top:5px;">{{ $settings['focal_person_name'] }}{{ $settings['focal_person_position'] ? ', '.$settings['focal_person_position'] : '' }}. {{ $settings['focal_person_contact'] }}</div></div>
</td></tr></table>
</td></tr></table>
</body></html>
