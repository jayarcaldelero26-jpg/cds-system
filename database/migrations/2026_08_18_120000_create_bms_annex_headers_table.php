<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bms_annex_headers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('location')->nullable();
            $table->date('date_conducted')->nullable();
            $table->string('start_end_time')->nullable();
            $table->string('start_gps')->nullable();
            $table->string('end_gps')->nullable();
            $table->string('length_of_transect')->nullable();
            $table->string('weather_condition')->nullable();
            $table->string('elevation')->nullable();
            $table->string('ecosystem_type')->nullable();
            $table->string('species_observed')->nullable();
            $table->string('observer')->nullable();
            $table->timestamps();
            $table->unique(['protected_area_id', 'category', 'start_date', 'end_date'], 'bms_annex_header_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bms_annex_headers');
    }
};
