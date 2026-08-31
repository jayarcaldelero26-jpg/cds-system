<?php

namespace Database\Seeders;

use App\Models\ComplianceAlertSetting;
use Illuminate\Database\Seeder;

class ComplianceAlertBossDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = ComplianceAlertSetting::query()->firstOrCreate(['singleton_key' => 1], [
            'alerts_enabled' => false, 'automatic_send_enabled' => false,
            'send_time' => '08:00', 'timezone' => 'Asia/Manila',
        ]);
        $defaults = [
            'email_subject' => ['Overdue Submission of PA-related Reports', '⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports'],
            'to_label' => [null, ''],
            'attention_line' => [null, ''],
            'signatory_name' => ['The PENR Officer', 'PABLITO M. OFRECIA'],
            'signatory_position' => ['Provincial Environment and Natural Resources Officer', 'PENR Officer'],
            'office_name' => ['PENRO Davao Oriental', 'PENRO Mati, Davao Oriental'],
            'office_address' => ['PENRO Davao Oriental', 'PENRO Mati, Davao Oriental'],
            'focal_person_name' => ['CDS Focal Person', 'Richelle A. Benemerito'],
            'focal_person_position' => ['CDS Focal Person', 'EMS I'],
            'focal_person_contact' => ['For inquiries, please contact the CDS focal person.', 'Provincial Protected Area Focal Person of PENRO Mati'],
            'system_generated_footer_text' => [
                'This is a system-generated notification sent automatically by the Enhanced Digital Alert and Tracking System (eDATS). Notifications for a report will cease once the submission is recorded as compliant in eDATS.',
                'This is a system-generated notification sent automatically by the Enhanced Digital Alert and Tracking System (eDATS). Notifications for a report will cease once the submission is recorded as compliant in eDATS.',
            ],
        ];
        $updates = [];
        foreach ($defaults as $column => [$legacy, $value]) {
            if ($settings->{$column} === null || trim((string) $settings->{$column}) === '' || ($legacy !== null && $settings->{$column} === $legacy)) {
                $updates[$column] = $value;
            }
        }
        foreach ([
            'email_subject' => 'jayarcaldelero26@gmail.com',
            'to_label' => 'MHRWS',
            'attention_line' => 'Lechoncito',
        ] as $column => $placeholder) {
            if ($settings->{$column} === $placeholder) {
                $updates[$column] = $column === 'email_subject'
                    ? '⚠ PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports'
                    : '';
            }
        }
        if ($updates !== []) {
            $settings->update($updates);
        }
    }

}
