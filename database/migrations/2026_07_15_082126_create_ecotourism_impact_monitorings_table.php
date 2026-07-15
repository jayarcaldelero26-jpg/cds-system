<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecotourism_monitorings', function (Blueprint $table) {
            $table->id();
            // Link sa Protected Area
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();

            // Ecotourism Details
            $table->string('site_name'); // E.g., Mt. Hamiguitan Trail, Pujada Island
            $table->date('monitoring_date');
            $table->integer('visitors_count')->default(0);

            // Impact Assessment
            $table->string('impact_rating')->default('Low'); // Low, Moderate, High
            $table->text('issues_observed')->nullable(); // Mga nakita nga problema
            $table->text('recommendations')->nullable();

            // Status ug File
            $table->string('status')->default('Under Review'); // Under Review, Approved
            $table->string('attachment')->nullable(); // PDF file

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecotourism_monitorings');
    }
};
