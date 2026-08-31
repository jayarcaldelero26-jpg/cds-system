<?php

namespace App\Services\Compliance;

use App\Domain\Modules\ProgramArea;
use App\Models\ComplianceNotificationRun;
use App\Models\EngpReportSubmission;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

final class ComplianceAlertTemplateResolver
{
    public const FAMILY_PROTECTED_AREA = 'protected_area';
    public const FAMILY_ENGP = 'engp';
    public const PROTECTED_AREA_OVERDUE_SUBJECT = '⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports';

    /** @return array<string, array<string, string>> */
    public function defaults(): array
    {
        return [
            'protected_area_due_soon' => ['subject' => 'REMINDER: Upcoming Deadline for Submission of Protected Area Management Report', 'introductory_text' => 'Please be reminded that the following Protected Area Management-related report is due for submission within three (3) days.', 'instruction_text' => 'Kindly ensure that the required report, together with the necessary supporting documents, is submitted through the prescribed official channel on or before the indicated deadline.', 'report_heading' => 'REPORT DUE FOR SUBMISSION', 'closing_text' => 'Please ensure that the required report is submitted through the prescribed official channel and recorded in eDATS within the prescribed period. Timely submission is requested to facilitate monitoring and consolidation at the PENRO level.', 'closing_directive' => 'PLEASE BE GUIDED ACCORDINGLY.', 'to_default' => 'The OIC, PASu', 'attention_default' => '', 'focal_footer_text' => 'Protected Area Focal Person'],
            'protected_area_overdue' => ['subject' => self::PROTECTED_AREA_OVERDUE_SUBJECT, 'introductory_text' => 'This is to respectfully remind your office that the deadline for the submission of the Protected Area Management-related report has already lapsed. We reiterate the importance of submitting your report to the PENRO as soon as possible.', 'instruction_text' => 'Below is/are the pending report(s) that require immediate submission:', 'report_heading' => 'Protected Area', 'closing_text' => 'Please be advised that failure to comply may result in a rating of 1 (Poor) in the OPCR/IPCR. Thus, we highly encourage your immediate action to avoid any negative implications on our performance targets.', 'closing_directive' => 'FOR INFORMATION AND STRICT COMPLIANCE, PLEASE.', 'to_default' => 'The OIC, PASu', 'attention_default' => 'Chief, Conservation and Development Section', 'focal_footer_text' => 'Provincial Protected Area Focal Person'],
            'engp_due_soon' => ['subject' => 'REMINDER: Upcoming Deadline for Submission of ENGP Report', 'introductory_text' => 'Please be reminded that the following Enhanced National Greening Program (ENGP)-related report is due for submission within three (3) days.', 'instruction_text' => 'Kindly ensure that the required report, together with the necessary supporting documents, is submitted through the prescribed official channel on or before the indicated deadline.', 'report_heading' => 'REPORT DUE FOR SUBMISSION', 'closing_text' => 'Please ensure that the required report is submitted through the prescribed official channel and recorded in eDATS within the prescribed period. Timely submission is requested to facilitate monitoring and consolidation at the PENRO level.', 'closing_directive' => 'PLEASE BE GUIDED ACCORDINGLY.', 'to_default' => 'The concerned CENR Officer', 'attention_default' => 'Chief, Conservation and Development Section and the ENGP Coordinator', 'focal_footer_text' => 'Provincial ENGP Focal Person'],
            'engp_overdue' => ['subject' => 'IMMEDIATE ACTION REQUIRED: Submission of Regular ENGP Monitoring and Accomplishment Reports', 'introductory_text' => 'The deadline for submission of the Regular ENGP Monitoring and Accomplishment/Progress Reports has already lapsed.', 'instruction_text' => 'Below is/are the pending report(s) that require immediate submission:', 'report_heading' => 'Implementing Office', 'closing_text' => 'Failure to comply may result in a rating of 1 (Poor) in OPCR/IPCR. Immediate action is encouraged to avoid negative implications on collective performance targets.', 'closing_directive' => 'FOR INFORMATION AND STRICT COMPLIANCE, PLEASE.', 'to_default' => 'The concerned CENR Officer', 'attention_default' => 'Chief, Conservation and Development Section and the ENGP Coordinator', 'focal_footer_text' => 'Provincial ENGP Focal Person'],
        ];
    }

    /** @param array<string,mixed> $settings @return array<string, array<string,string>> */
    public function templateSettings(array $settings): array
    {
        $saved = Arr::only((array) ($settings['template_settings'] ?? []), array_keys($this->defaults()));
        $templates = [];

        foreach ($this->defaults() as $key => $defaults) {
            $templates[$key] = array_map(
                'strval',
                array_replace($defaults, Arr::only((array) ($saved[$key] ?? []), array_keys($defaults)))
            );
        }

        // A legacy saved customization must not alter the approved PA overdue header.
        $templates['protected_area_overdue']['subject'] = self::PROTECTED_AREA_OVERDUE_SUBJECT;
        return $templates;
    }

    public function familyFor(OverdueReport $report): string
    {
        $programArea = trim((string) $report->programArea);
        $developmentAreas = [ProgramArea::ENGP->value, ProgramArea::DEVELOPMENT->value, ProgramArea::ENGP->label(), ProgramArea::DEVELOPMENT->label(), 'National Greening Program'];
        return in_array($programArea, $developmentAreas, true) || $report->sourceType === EngpReportSubmission::class ? self::FAMILY_ENGP : self::FAMILY_PROTECTED_AREA;
    }

    /** @param array<string,mixed> $settings @return array<string,string> */
    public function recipientDefaultsFor(OverdueReport $report, array $settings = []): array
    {
        $family = $this->familyFor($report);
        $template = $family === self::FAMILY_ENGP ? 'engp_due_soon' : 'protected_area_due_soon';
        $content = $this->templateSettings($settings)[$template];

        return [
            'default_to' => $content['to_default'],
            'default_attention' => $content['attention_default'],
            'focal_label' => $content['focal_footer_text'],
        ];
    }
    /** @param Collection<int, OverdueReport> $reports @param array<string,mixed> $settings @return array<string,string> */
    public function presentationFor(Collection $reports, ?string $alertType, array $settings = []): array
    {
        $family = $this->familyFor($reports->first());
        $isEngp = $family === self::FAMILY_ENGP;
        $template = match ($alertType) {
            ComplianceNotificationRun::ALERT_DUE_SOON => $isEngp ? 'engp_due_soon' : 'protected_area_due_soon',
            ComplianceNotificationRun::ALERT_OVERDUE => $isEngp ? 'engp_overdue' : 'protected_area_overdue',
            default => 'standard_due_today',
        };
        $content = $template === 'standard_due_today'
            ? [
                'subject' => (string) ($settings['email_subject'] ?? $settings['subject'] ?? 'Compliance Alert'),
                'introductory_text' => 'The following report deadline is today and requires immediate action.',
                'instruction_text' => 'Below is/are the pending report(s) that require immediate submission:',
                'report_heading' => $isEngp ? 'Implementing Office' : 'Protected Area',
                'closing_text' => (string) ($settings['compliance_warning_text'] ?? ''),
                'closing_directive' => (string) ($settings['strict_compliance_text'] ?? 'FOR INFORMATION AND STRICT COMPLIANCE, PLEASE.'),
            ]
            : $this->templateSettings($settings)[$template];
        return [...$content, 'family' => $family, 'template' => $template, 'destination_label' => $isEngp ? 'Implementing Office' : 'Protected Area', 'group_heading_label' => $content['report_heading'] ?? ($isEngp ? 'Implementing Office' : 'Protected Area'), 'default_to' => $content['to_default'] ?? ($isEngp ? 'The concerned CENR Officer' : 'The OIC, PASu'), 'default_attention' => $content['attention_default'] ?? '', 'focal_label' => $content['focal_footer_text'] ?? ($isEngp ? 'Provincial ENGP Focal Person' : 'Protected Area Focal Person')];
    }
}