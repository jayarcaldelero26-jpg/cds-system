<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('alerts_enabled')->default(false);
            $table->boolean('automatic_send_enabled')->default(false);
            $table->string('send_time', 5)->default('08:00');
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('email_subject')->nullable();
            $table->string('to_label')->nullable();
            $table->string('attention_line')->nullable();
            $table->string('from_line')->nullable();
            $table->string('memorandum_subject')->nullable();
            $table->text('introductory_text')->nullable();
            $table->text('compliance_warning_text')->nullable();
            $table->text('strict_compliance_text')->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signatory_position')->nullable();
            $table->string('office_name')->nullable();
            $table->string('office_address')->nullable();
            $table->string('focal_person_name')->nullable();
            $table->string('focal_person_position')->nullable();
            $table->text('focal_person_contact')->nullable();
            $table->text('do_not_reply_text')->nullable();
            $table->text('system_generated_footer_text')->nullable();
            $table->string('sender_display_name')->nullable();
            $table->string('fallback_recipient_email')->nullable();
            $table->json('fallback_cc_emails')->nullable();
            $table->string('test_recipient_email')->nullable();
            $table->timestamps();
        });

        Schema::create('compliance_alert_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_office')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');
            $table->json('cc_emails')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['protected_area_id', 'is_active'], 'compliance_alert_recipient_pa_lookup');
            $table->index(['target_office', 'is_active'], 'compliance_alert_recipient_office_lookup');
        });

        Schema::table('compliance_notification_runs', function (Blueprint $table): void {
            $table->string('run_type', 20)->default('automatic')->after('is_manual');
        });

        Schema::table('compliance_notification_run_reports', function (Blueprint $table): void {
            $table->json('snapshot')->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_notification_run_reports', function (Blueprint $table): void {
            $table->dropColumn('snapshot');
        });
        Schema::table('compliance_notification_runs', function (Blueprint $table): void {
            $table->dropColumn('run_type');
        });
        Schema::dropIfExists('compliance_alert_recipients');
        Schema::dropIfExists('compliance_alert_settings');
    }
};
