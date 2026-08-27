<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_plan_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('management_plan_type_id')->constrained('management_plan_types')->restrictOnDelete();
            $table->foreignId('protected_area_id')->constrained('protected_areas')->restrictOnDelete();
            $table->string('plan_name')->nullable();
            $table->unsignedSmallInteger('planning_period_start')->nullable();
            $table->unsignedSmallInteger('planning_period_end')->nullable();
            $table->string('lead_office')->nullable();
            $table->string('lead_preparer')->nullable();
            $table->date('date_preparation_started')->nullable();
            $table->boolean('twg_constituted')->nullable();
            $table->boolean('stakeholder_consultation_conducted')->nullable();
            $table->json('consultation_dates')->nullable();
            $table->json('completeness_checklist')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('pamb_resolution_number')->nullable();
            $table->date('pamb_resolution_date')->nullable();
            $table->date('cenro_endorsement_date')->nullable();
            $table->date('penro_endorsement_date')->nullable();
            $table->date('red_endorsement_date')->nullable();
            $table->date('date_received_bmb')->nullable();
            $table->date('denr_affirmation_date')->nullable();
            $table->string('affirmation_reference')->nullable();
            $table->string('harmonized_adsdpp', 20)->nullable();
            $table->string('harmonized_clup', 20)->nullable();
            $table->string('other_plans_integrated', 20)->nullable();
            $table->json('documents')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['management_plan_type_id', 'protected_area_id'], 'management_plan_profiles_type_pa_index');
            $table->index('approval_status');
        });

        Schema::table('management_plans', function (Blueprint $table): void {
            $table->foreignId('management_plan_profile_id')->nullable()->after('management_plan_type_id')->constrained('management_plan_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('management_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('management_plan_profile_id');
        });

        Schema::dropIfExists('management_plan_profiles');
    }
};
