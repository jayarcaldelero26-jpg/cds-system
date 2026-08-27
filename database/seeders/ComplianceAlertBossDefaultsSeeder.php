<?php

namespace Database\Seeders;

use App\Models\ComplianceAlertRecipient;
use App\Models\ComplianceAlertSetting;
use Illuminate\Database\Seeder;

class ComplianceAlertBossDefaultsSeeder extends Seeder
{
    private const CC_EMAILS = [
        'penromaticds@gmail.com', 'benemerito.RB@gmail.com', 'nely.maimad11@gmail.com',
        'hingpitelmarie@gmail.com', 'duayelmarie@gmail.com', 'edhingpit01@gmail.com',
    ];

    public function run(): void
    {
        $this->seedRecipient('Baganga', 'The Deputy PASu of Baganga', 'Chief, Conservation and Development Section', 'cenrobaganga@denr.gov.ph');
        $this->seedRecipient('Hamiguitan', 'The OIC, PASu of Hamiguitan', '', 'mthamiguitan@denr.gov.ph');
        $this->seedRecipient('Mati', 'The Deputy PASu of Mati', 'Chief, Conservation and Development Section', 'cenromati@denr.gov.ph');

        $settings = ComplianceAlertSetting::query()->firstOrCreate(['singleton_key' => 1], [
            'alerts_enabled' => false, 'automatic_send_enabled' => false,
            'send_time' => '08:00', 'timezone' => 'Asia/Manila',
        ]);
        $defaults = [
            'email_subject' => ['Overdue Submission of PA-related Reports', 'PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports'],
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
                'This is a system-generated notification sent every working day. It will cease only once all required submissions are completed and confirmed by the Records Officer.',
                'This automated reminder concerns reports whose authoritative submission or receipt has not yet been recorded by PENRO. Reminders cease once the report is recorded as received or submitted by PENRO. Records verification is a separate internal audit process.',
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
                    ? 'PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports'
                    : '';
            }
        }
        if (blank($settings->fallback_cc_emails)) {
            $updates['fallback_cc_emails'] = self::CC_EMAILS;
        }
        if ($updates !== []) {
            $settings->update($updates);
        }
    }

    private function seedRecipient(string $office, string $name, string $attention, string $email): void
    {
        $mapping = ComplianceAlertRecipient::query()->whereNull('protected_area_id')
            ->whereRaw('LOWER(TRIM(target_office)) = ?', [mb_strtolower($office)])->first();
        if ($mapping) {
            return;
        }
        ComplianceAlertRecipient::query()->create([
            'target_office' => $office, 'recipient_name' => $name, 'attention_line' => $attention,
            'recipient_email' => $email, 'cc_emails' => self::CC_EMAILS, 'is_active' => true,
            'notes' => 'Boss Apps Script initial compliance-alert mapping.',
        ]);
    }
}
