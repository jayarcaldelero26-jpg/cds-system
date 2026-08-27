<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CC_EMAILS = [
        'penromaticds@gmail.com',
        'benemerito.RB@gmail.com',
        'nely.maimad11@gmail.com',
        'hingpitelmarie@gmail.com',
        'duayelmarie@gmail.com',
        'edhingpit01@gmail.com',
    ];

    public function up(): void
    {
        $this->seedRecipient('Baganga', 'The Deputy PASu of Baganga', 'Chief, Conservation and Development Section', 'cenrobaganga@denr.gov.ph');
        $this->seedRecipient('Hamiguitan', 'The OIC, PASu of Hamiguitan', '', 'mthamiguitan@denr.gov.ph');
        $this->seedRecipient('Mati', 'The Deputy PASu of Mati', 'Chief, Conservation and Development Section', 'cenromati@denr.gov.ph');

    }

    private function seedRecipient(string $office, string $name, string $attention, string $email): void
    {
        $exists = DB::table('compliance_alert_recipients')
            ->whereNull('protected_area_id')
            ->whereRaw('LOWER(TRIM(target_office)) = ?', [mb_strtolower($office)])
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('compliance_alert_recipients')->insert([
            'target_office' => $office,
            'recipient_name' => $name,
            'attention_line' => $attention,
            'recipient_email' => $email,
            'cc_emails' => json_encode(self::CC_EMAILS, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'notes' => 'Boss Apps Script initial compliance-alert mapping.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve operational mappings/settings on rollback; this migration is intentionally forward-only.
    }
};
