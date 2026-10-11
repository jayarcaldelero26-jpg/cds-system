<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_tat_assessment_scope_versions')) {
            Schema::create('office_tat_assessment_scope_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('unit', 24);
                $table->foreignId('protected_area_id')->nullable();
                $table->foreign('protected_area_id', 'office_tat_scope_pa_fk')->references('id')->on('protected_areas')->cascadeOnDelete();
                $table->json('workflow_keys');
                $table->boolean('coverage_complete')->default(false);
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('configured_by')->nullable();
                $table->foreign('configured_by', 'office_tat_scope_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['unit', 'protected_area_id', 'version'], 'office_tat_scope_version_unique');
                $table->index(['unit', 'protected_area_id', 'created_at'], 'office_tat_scope_current_idx');
            });
        }

        if (! Schema::hasTable('office_tat_technical_classification_versions')) {
            Schema::create('office_tat_technical_classification_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 24);
                $table->string('workflow_key', 120);
                $table->boolean('is_highly_technical');
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('configured_by')->nullable();
                $table->foreign('configured_by', 'office_tat_tech_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['domain', 'workflow_key', 'version'], 'office_tat_tech_current_idx');
            });
        }

        if (! Schema::hasTable('office_tat_tie_decisions')) {
            Schema::create('office_tat_tie_decisions', function (Blueprint $table): void {
                $table->id();
                $table->string('unit', 24);
                $table->string('period_key', 16);
                $table->string('metric_fingerprint', 64);
                $table->json('participant_keys');
                $table->json('ordered_participant_keys');
                $table->text('reason');
                $table->foreignId('approved_by')->nullable();
                $table->foreign('approved_by', 'office_tat_tie_approver_fk')->references('id')->on('users')->nullOnDelete();
                $table->timestamp('approved_at');
                $table->timestamps();
                $table->index(['unit', 'period_key', 'metric_fingerprint'], 'office_tat_tie_fingerprint_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('office_tat_tie_decisions');
        Schema::dropIfExists('office_tat_technical_classification_versions');
        Schema::dropIfExists('office_tat_assessment_scope_versions');
    }
};
