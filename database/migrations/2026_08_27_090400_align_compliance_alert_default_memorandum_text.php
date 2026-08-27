<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('compliance_alert_settings')
            ->where('email_subject', 'Overdue Submission of PA-related Reports')
            ->update(['email_subject' => 'PRIORITY ACTION REQUIRED: Overdue Submission of PA-related Reports']);

        DB::table('compliance_alert_settings')
            ->where('introductory_text', 'This is to respectfully remind your office of the required submission of reports for activities already conducted. Timely submission supports the monitoring, records management, and compliance responsibilities of the CDS.')
            ->update(['introductory_text' => 'The required PA Management-related report deadline has already lapsed. Kindly submit the required report(s) to PENRO as soon as possible.']);

        DB::table('compliance_alert_settings')
            ->where('compliance_warning_text', 'Failure to comply may result in a rating of 1 (Poor) in OPCR/IPCR.')
            ->update(['compliance_warning_text' => 'Failure to comply may result in a rating of 1 (Poor) in OPCR/IPCR. Immediate action is encouraged to avoid negative implications on collective performance targets.']);

        DB::table('compliance_alert_settings')
            ->where('system_generated_footer_text', 'This system-generated notification is sent daily and will stop only once the required submission is completed and confirmed by the Records Officer.')
            ->update(['system_generated_footer_text' => 'This is a system-generated notification sent every working day. It will cease only once all required submissions are completed and confirmed by the Records Officer.']);
    }

    public function down(): void
    {
        // These are forward-only operational-default improvements. Do not overwrite administrator changes on rollback.
    }
};
