<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('imea_facility_maintenance_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained('protected_areas')->restrictOnDelete();
            $table->string('target_office');
            $table->string('activity_name');
            $table->string('document_type');
            $table->string('quarter', 20);
            $table->string('date_conducted')->nullable();
            $table->date('date_accomplished')->nullable();
            $table->date('date_report_released_cenro')->nullable();
            $table->date('date_received_penro')->nullable();
            $table->date('date_endorsed_regional')->nullable();
            $table->string('mov_file_name')->nullable();
            $table->string('mov_file_path')->nullable();
            $table->string('mov_mime_type')->nullable();
            $table->unsignedBigInteger('mov_size')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['quarter', 'protected_area_id'], 'imea_maintenance_quarter_pa_index');
        });
    }
    public function down(): void { Schema::dropIfExists('imea_facility_maintenance_reports'); }
};
