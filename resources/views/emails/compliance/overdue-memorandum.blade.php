<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $presentation['subject'] ?? ($settings['email_subject'] ?? $settings['subject']) }}</title>
<style>
@media only screen and (max-width:600px){.memo-shell{padding:16px 8px!important}.memo-content{padding:24px 16px!important}.memo-table th,.memo-table td{padding:7px 5px!important}.memo-table{font-size:11px!important;line-height:16px!important}}
</style></head>
@php
    $template = $presentation['template'] ?? 'standard_due_today';
    $isReminder = in_array($template, ['protected_area_due_soon', 'engp_due_soon'], true);
    $isEngp = in_array($template, ['engp_due_soon', 'engp_overdue'], true);
    $destinationLabel = $presentation['destination_label'] ?? ($isEngp ? 'Implementing Office' : 'Protected Area');
    $groupHeadingLabel = $presentation['group_heading_label'] ?? $destinationLabel;
    $toLine = $recipient['name'] ?? ($presentation['default_to'] ?? 'The OIC, PASu');
    $attentionLine = $recipient['attention_line'] ?? ($presentation['default_attention'] ?? '');
    $focalLabel = $presentation['focal_label'] ?? ($isEngp ? 'Provincial ENGP Focal Person' : 'Protected Area Focal Person');
    $isCanonicalOverdue = $template === 'protected_area_overdue';
    $footerText = $isCanonicalOverdue ? 'This is a system-generated notification sent automatically by the Enhanced Digital Alert and Tracking System (eDATS). Notifications for a report will cease once the submission is recorded as compliant in eDATS.' : ($settings['system_generated_footer_text'] ?? '');
@endphp
<body style="margin:0;padding:0;background:#f3f6f8;color:#1f2937;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="memo-shell" style="background:#f3f6f8;padding:24px 12px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:760px;background:#fffdf7;border:1px solid #d7dee5;"><tr><td class="memo-content" style="padding:36px 34px 28px;color:#1f2937;">
@if ($isReminder)
<div style="text-align:center;font-size:18px;line-height:26px;font-weight:700;letter-spacing:.6px;color:#14532d;margin-bottom:24px;">REMINDER</div>
<p style="font-size:14px;line-height:22px;margin:0 0 16px;">{!! nl2br(e($presentation['introductory_text'] ?? '')) !!}</p>
<p style="font-size:14px;line-height:22px;margin:0 0 20px;">{!! nl2br(e($presentation['instruction_text'] ?? '')) !!}</p>
<div style="font-size:14px;font-weight:700;color:#14532d;margin:0 0 10px;">{{ $presentation['report_heading'] ?? 'REPORT DUE FOR SUBMISSION' }}</div>
@foreach ($groups as $group)
<div style="font-size:14px;line-height:22px;margin:16px 0 8px;"><strong>{{ $destinationLabel }}:</strong> {{ $isEngp ? $group['target_office'] : $group['protected_area_name'] }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="memo-table" style="width:100%;border-collapse:collapse;font-size:12px;line-height:18px;table-layout:fixed;"><thead><tr><th align="left" style="width:7%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">No.</th><th align="left" style="width:55%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">Activity / Document Type</th><th align="left" style="width:23%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">Deadline</th><th align="center" style="width:15%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">Days Remaining</th></tr></thead><tbody>
@foreach ($group['reports'] as $index => $report)<tr><td style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;">{{ $index + 1 }}</td><td style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;"><strong>{{ $report['activity'] }}</strong><br><span style="color:#4b5563;">{{ $report['document_type'] }} · {{ $report['module'] }}</span></td><td style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;">{{ \Carbon\Carbon::parse($report['deadline'])->locale('en')->isoFormat('MMMM D, YYYY') }}</td><td align="center" style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;color:#166534;font-weight:700;">3</td></tr>@endforeach
</tbody></table>
@endforeach
<p style="font-size:14px;line-height:22px;margin:24px 0 18px;">{!! nl2br(e($presentation['closing_text'] ?? '')) !!}</p>
<p style="font-size:14px;line-height:22px;margin:0 0 30px;font-weight:700;">{{ $presentation['closing_directive'] ?? 'PLEASE BE GUIDED ACCORDINGLY.' }}</p>
@else
<div style="text-align:center;font-size:21px;line-height:28px;font-weight:700;letter-spacing:1px;color:#111827;margin-bottom:30px;">MEMORANDUM</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-size:14px;line-height:21px;margin-bottom:22px;"><tr><td style="width:92px;font-weight:700;vertical-align:top;">TO:</td><td>{{ $toLine }}</td></tr>
@if (filled($attentionLine))<tr><td style="font-weight:700;vertical-align:top;">ATTENTION:</td><td>{{ $attentionLine }}</td></tr>@endif
<tr><td style="font-weight:700;vertical-align:top;">FROM:</td><td>{{ $settings['from_line'] ?? $settings['from'] }}</td></tr><tr><td style="font-weight:700;vertical-align:top;">SUBJECT:</td><td style="font-weight:700;">{{ $isEngp ? 'Submission of Regular ENGP Monitoring and Accomplishment Reports' : 'Submission of Reports of the Activities Already Conducted' }}</td></tr></table>
@php($openingText = $presentation['introductory_text'] ?? (($alertType ?? null) === 'DUE_TODAY' ? 'The following report deadline is today and requires immediate action.' : ''))
<p style="font-size:14px;line-height:22px;margin:0 0 18px;">{!! nl2br(e($openingText)) !!}</p>
<p style="font-size:14px;line-height:22px;margin:0 0 18px;font-weight:700;">{!! nl2br(e($presentation['instruction_text'] ?? 'Below is/are the pending report(s) that require immediate submission:')) !!}</p>
@foreach ($groups as $group)
<div style="margin:22px 0 8px;color:#155e9b;font-size:15px;line-height:22px;font-weight:700;">{{ $isEngp ? $groupHeadingLabel.': '.$group['target_office'] : $group['protected_area_name'] }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="memo-table" style="width:100%;border-collapse:collapse;font-size:12px;line-height:18px;table-layout:fixed;"><thead><tr><th align="left" style="width:7%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">No.</th><th align="left" style="width:55%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">Activity (Document Type)</th><th align="left" style="width:23%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">Deadline</th><th align="center" style="width:15%;border:1px solid #9db8ca;background:#dceef9;color:#123047;padding:8px 6px;">Days Overdue</th></tr></thead><tbody>
@foreach ($group['reports'] as $index => $report)<tr><td style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;">{{ $index + 1 }}</td><td style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;word-break:break-word;"><strong>{{ ($report['compliance_issue'] ?? null) === 'MOV Not Yet Submitted' ? 'MOV NOT YET SUBMITTED: ' : '' }}{{ $report['activity'] }}</strong><br><span style="color:#4b5563;">{{ $report['document_type'] }}</span></td><td style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;word-break:break-word;">{{ \Carbon\Carbon::parse($report['deadline'])->locale('en')->isoFormat('dddd, MMMM D, YYYY') }}</td><td align="center" style="border:1px solid #d7dee5;padding:8px 6px;vertical-align:top;color:#b42318;font-weight:700;">{{ $report['days_overdue'] }}</td></tr>@endforeach
</tbody></table>
@endforeach
@if ($isCanonicalOverdue)<p style="font-size:14px;line-height:22px;margin:24px 0 10px;">Please be advised that failure to comply may result in a <strong>rating of 1 (Poor)</strong> in the <strong>OPCR/IPCR</strong>. Thus, we highly encourage your immediate action to avoid any negative implications on our performance targets.</p><p style="font-size:14px;line-height:22px;margin:0 0 30px;font-weight:700;">FOR INFORMATION AND STRICT COMPLIANCE, PLEASE.</p>@else<p style="font-size:14px;line-height:22px;margin:24px 0 10px;">{!! nl2br(e($presentation['closing_text'] ?? $settings['compliance_warning_text'])) !!}</p><p style="font-size:14px;line-height:22px;margin:0 0 30px;font-weight:700;">{{ $presentation['closing_directive'] ?? 'FOR INFORMATION AND STRICT COMPLIANCE, PLEASE.' }}</p>@endif
@endif
@php($officeName = (string) ($settings['office_name'] ?? ''))
<div style="font-size:14px;line-height:21px;margin:0 0 30px;"><div style="font-weight:700;">(Sgd.) {{ $settings['signatory_name'] }}</div><div>{{ $settings['signatory_position'] }}</div><div>{{ $officeName }}</div>@if (filled($settings['office_address'] ?? null) && trim((string) $settings['office_address']) !== trim($officeName))<div>{{ $settings['office_address'] }}</div>@endif</div>
<div style="border-top:1px solid #e5e7eb;padding-top:16px;color:#b42318;font-size:11px;line-height:17px;font-style:italic;"><div>{!! nl2br(e($footerText)) !!}</div><div style="margin-top:7px;font-weight:700;">{{ $settings['do_not_reply_text'] ?? 'PLEASE DO NOT REPLY.' }}</div><div style="margin-top:5px;"><strong>{{ $focalLabel }}:</strong> {{ $settings['focal_person_position'] ? $settings['focal_person_position'].' ' : '' }}{{ $settings['focal_person_name'] }}. {{ $settings['focal_person_contact'] }}</div></div>
</td></tr></table></td></tr></table>
</body></html>