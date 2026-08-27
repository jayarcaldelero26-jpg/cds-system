<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_notification_runs')) {
            Schema::create('compliance_notification_runs', function (Blueprint $table): void {
                $table->id();
                $table->date('run_date');
                $table->string('recipient_key', 128);
                $table->json('recipients');
                $table->json('cc_recipients')->nullable();
                $table->string('subject');
                $table->unsignedInteger('report_count')->default(0);
                $table->string('status', 20);
                $table->boolean('is_manual')->default(false);
                $table->timestamp('sent_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('payload')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['run_date', 'recipient_key', 'is_manual'], 'compliance_notification_runs_dedup_lookup');
                $table->index(['run_date', 'status'], 'compliance_notification_runs_status_lookup');
            });
        }

        if (! Schema::hasTable('compliance_notification_run_reports')) {
            Schema::create('compliance_notification_run_reports', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('compliance_notification_run_id');
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->timestamps();

                $table->unique(['compliance_notification_run_id', 'source_type', 'source_id'], 'compliance_notification_run_reports_unique');
            });
        }

        $foreignKeys = collect(Schema::getForeignKeys('compliance_notification_run_reports'))->pluck('name');
        if (! $foreignKeys->contains('cnrr_run_fk')) {
            Schema::table('compliance_notification_run_reports', function (Blueprint $table): void {
                $table->foreign('compliance_notification_run_id', 'cnrr_run_fk')->references('id')->on('compliance_notification_runs')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_notification_run_reports');
        Schema::dropIfExists('compliance_notification_runs');
    }
};
