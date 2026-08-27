<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('compliance_alert_settings')
            ->where('email_subject', 'jayarcaldelero26@gmail.com')
            ->update(['email_subject' => 'PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports', 'updated_at' => now()]);

        DB::table('compliance_alert_settings')
            ->where('to_label', 'MHRWS')
            ->update(['to_label' => '', 'updated_at' => now()]);

        DB::table('compliance_alert_settings')
            ->where('attention_line', 'Lechoncito')
            ->update(['attention_line' => '', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Known test placeholders are not restored on rollback.
    }
};
