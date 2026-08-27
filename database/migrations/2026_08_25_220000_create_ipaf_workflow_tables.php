<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ipaf_revenue_collections', function (Blueprint $table): void {
            $table->id(); $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->string('target_office'); $table->string('activity_name')->default('Revenue Collection');
            $table->string('document_type'); $table->unsignedTinyInteger('reporting_month'); $table->unsignedSmallInteger('reporting_year');
            $table->decimal('total_collected', 16, 2); $table->date('date_report_released_cenro')->nullable(); $table->date('date_received_penro')->nullable(); $table->date('date_endorsed_regional')->nullable();
            $table->string('mov_file_name')->nullable(); $table->string('mov_file_path')->nullable(); $table->string('mov_mime_type')->nullable(); $table->unsignedBigInteger('mov_size')->nullable(); $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->index(['reporting_year', 'reporting_month', 'protected_area_id'], 'ipaf_revenue_period_pa_index');
        });
        Schema::create('ipaf_management_reports', function (Blueprint $table): void {
            $table->id(); $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->string('target_office'); $table->string('activity_name')->default('Management of Integrated Area Protected Area Fund (IPAF)'); $table->string('document_type');
            $table->string('date_conducted')->nullable(); $table->date('date_accomplished')->nullable(); $table->date('date_report_released_cenro')->nullable(); $table->date('date_received_penro')->nullable(); $table->date('date_endorsed_regional')->nullable();
            $table->string('mov_file_name')->nullable(); $table->string('mov_file_path')->nullable(); $table->string('mov_mime_type')->nullable(); $table->unsignedBigInteger('mov_size')->nullable(); $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('ipaf_management_reports'); Schema::dropIfExists('ipaf_revenue_collections'); }
};
