<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engp_report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_key');
            $table->string('office');
            $table->string('section_name')->nullable();
            $table->string('activity_name');
            $table->string('document_type');
            $table->unsignedSmallInteger('reporting_year');
            $table->string('period_key');
            $table->string('period_label');
            $table->date('deadline_submission');
            $table->date('date_received_penro')->nullable();
            $table->string('mov_file_path')->nullable();
            $table->string('mov_external_url', 2048)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workflow_key', 'office', 'reporting_year', 'period_key'], 'engp_submission_period_unique');
            $table->index(['workflow_key', 'reporting_year']);
        });

        Schema::create('engp_report_release_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('engp_report_submission_id')->constrained('engp_report_submissions')->cascadeOnDelete();
            $table->string('period_component');
            $table->string('component_label');
            $table->date('date_report_released_cenro');
            $table->timestamps();
            $table->unique(['engp_report_submission_id', 'period_component'], 'engp_release_component_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engp_report_release_events');
        Schema::dropIfExists('engp_report_submissions');
    }
};
