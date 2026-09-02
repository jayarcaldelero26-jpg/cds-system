<?php

use App\Services\Compliance\ComplianceRichTextSanitizer;

function renderComplianceMemorandum(string $template): string
{
    return view('emails.compliance.overdue-memorandum', [
        'groups' => [[
            'protected_area_name' => 'Sample Protected Area',
            'target_office' => 'CENRO Mati',
            'reports' => [[
                'activity' => 'Sample report',
                'document_type' => 'Report',
                'deadline' => '2026-09-03',
                'days_overdue' => 2,
                'compliance_issue' => 'Report Not Yet Submitted',
            ]],
        ]],
        'settings' => [
            'subject' => 'Compliance Alert',
            'email_subject' => 'Compliance Alert',
            'from' => 'PENRO Mati',
            'from_line' => 'PENRO Mati',
            'signatory_name' => 'Signatory',
            'signatory_position' => 'Position',
            'office_name' => 'PENRO Mati',
            'office_address' => '',
            'system_generated_footer_text' => 'System generated.',
            'do_not_reply_text' => 'PLEASE DO NOT REPLY.',
            'focal_person_position' => 'Focal',
            'focal_person_name' => 'Person',
            'focal_person_contact' => 'Contact',
            'compliance_warning_text' => 'Warning text.',
        ],
        'recipient' => ['name' => 'Recipient', 'destination' => 'Sample Protected Area'],
        'presentation' => [
            'template' => $template,
            'subject' => 'Compliance Alert',
            'introductory_text' => '<p>Please be advised that <strong>failure to comply</strong> may result in a rating of <em>Poor</em>.</p>',
            'instruction_text' => '<p><u>Submit the required report</u> through the prescribed channel.</p>',
            'closing_text' => '<p><span style="color:#b42318">This is an important reminder.</span></p>',
            'closing_directive' => '<p>PLEASE BE GUIDED ACCORDINGLY.</p>',
            'report_heading' => 'REPORT DUE FOR SUBMISSION',
        ],
        'alertType' => 'OVERDUE',
    ])->render();
}

test('compliance memorandum narrative uses compact single spacing and justified paragraphs', function () {
    $html = renderComplianceMemorandum('protected_area_overdue');

    expect($html)
        ->toContain('line-height:1.15')
        ->toContain('text-align:justify')
        ->not->toContain('font-size:14px;line-height:22px');
});

test('protected area and ENGP memoranda preserve the same rich text formatting pipeline', function () {
    $protectedArea = renderComplianceMemorandum('protected_area_overdue');
    $engp = renderComplianceMemorandum('engp_overdue');

    foreach ([$protectedArea, $engp] as $html) {
        expect($html)
            ->toContain('<strong>failure to comply</strong>')
            ->toContain('<em>Poor</em>')
            ->toContain('<u>Submit the required report</u>')
            ->toContain('<span style="color:#b42318">This is an important reminder.</span>')
            ->toContain('text-align:justify');
    }

    expect(substr_count($protectedArea, 'line-height:1.15'))->toBe(substr_count($engp, 'line-height:1.15'));
});

test('rich text sanitizer still supports memorandum formatting tags', function () {
    $html = app(ComplianceRichTextSanitizer::class)->render(
        '<p><strong>Bold</strong> <em>Italic</em> <u>Underline</u> <font color="#14532d">Green</font></p>',
        'text-align:justify;line-height:1.15;',
    );

    expect($html)
        ->toContain('<strong>Bold</strong>')
        ->toContain('<em>Italic</em>')
        ->toContain('<u>Underline</u>')
        ->toContain('<span style="color:#14532d">Green</span>')
        ->toContain('text-align:justify;line-height:1.15;');
});
