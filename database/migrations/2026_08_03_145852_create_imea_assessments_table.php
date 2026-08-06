<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imea_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();
            $table->string('pamo_name');
            $table->year('assessment_year');
            $table->string('assessment_period'); // e.g., Q1, Q2, Q3, Q4 o Annual

            // Core IMEA Parameters
            $table->decimal('visitor_arrivals', 10, 2)->nullable();
            $table->string('trail_condition')->nullable();
            $table->decimal('solid_waste_generation_kg', 10, 2)->nullable();
            $table->string('wildlife_disturbance')->nullable();
            $table->string('vegetation_damage')->nullable();
            $table->string('water_quality')->nullable();
            $table->boolean('carrying_capacity_compliance')->default(true);
            $table->decimal('community_benefits_income', 12, 2)->nullable();
            $table->decimal('visitor_satisfaction_rate', 5, 2)->nullable();

            // Remarks / Notes
            $table->text('biodiversity_impact_notes')->nullable();
            $table->text('environment_impact_notes')->nullable();
            $table->text('social_cultural_impact_notes')->nullable();
            $table->text('economic_impact_notes')->nullable();
            $table->text('general_remarks')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imea_assessments');
    }
};
