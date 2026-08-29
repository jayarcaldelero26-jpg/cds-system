<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conservation_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key');
            $table->foreignId('protected_area_id')->nullable()->constrained('protected_areas')->nullOnDelete();
            $table->string('target_office')->nullable();
            $table->string('activity_name')->nullable();
            $table->string('document_type')->nullable();
            $table->string('reporting_period')->nullable();
            $table->date('date_conducted')->nullable();
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

            $table->index(['workflow_key', 'protected_area_id'], 'conservation_reports_workflow_pa_index');
            $table->index(['workflow_key', 'reporting_period'], 'conservation_reports_workflow_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conservation_report_submissions');
    }
};