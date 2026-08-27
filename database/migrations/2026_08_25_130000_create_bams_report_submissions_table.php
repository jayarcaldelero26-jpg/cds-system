<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bams_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->nullable()->constrained('protected_areas')->nullOnDelete();
            $table->string('target_office')->nullable();
            $table->string('activity_name')->nullable();
            $table->string('document_type')->nullable();
            $table->string('semester');
            $table->string('date_conducted')->nullable();
            $table->date('date_accomplished')->nullable();
            $table->date('date_report_released_cenro')->nullable();
            $table->date('date_received_penro')->nullable();
            $table->date('date_endorsed_regional')->nullable();
            $table->string('mov_file_name')->nullable();
            $table->string('mov_file_path')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['semester', 'protected_area_id'], 'bams_reports_semester_pa_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bams_report_submissions');
    }
};
