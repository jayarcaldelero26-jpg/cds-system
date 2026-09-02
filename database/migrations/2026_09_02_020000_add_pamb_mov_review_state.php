<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conservation_report_submissions', function (Blueprint $table): void {
            $table->string('mov_processing_status')->nullable()->after('mov_file_path');
            $table->dateTime('mov_submitted_at')->nullable()->after('mov_processing_status');
            $table->foreignId('mov_submitted_by')->nullable()->after('mov_submitted_at')->constrained('users')->nullOnDelete();
            $table->dateTime('mov_reviewed_at')->nullable()->after('mov_submitted_by');
            $table->foreignId('mov_reviewed_by')->nullable()->after('mov_reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('mov_review_remarks')->nullable()->after('mov_reviewed_by');
            $table->index(['workflow_key', 'mov_processing_status'], 'conservation_reports_mov_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('conservation_report_submissions', function (Blueprint $table): void {
            $table->dropIndex('conservation_reports_mov_status_index');
            $table->dropForeign(['mov_submitted_by']);
            $table->dropForeign(['mov_reviewed_by']);
            $table->dropColumn(['mov_processing_status', 'mov_submitted_at', 'mov_submitted_by', 'mov_reviewed_at', 'mov_reviewed_by', 'mov_review_remarks']);
        });
    }
};
