<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('office_tat_approval_evidence')) {
            Schema::create('office_tat_approval_evidence', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('scope_key', 220);
                $table->string('storage_path', 500);
                $table->string('original_name', 255);
                $table->string('media_type', 120);
                $table->unsignedBigInteger('byte_size');
                $table->string('sha256', 64);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('office_tat_applicability_versions')) {
            Schema::create('office_tat_applicability_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('protected_area_id')->constrained('protected_areas')->cascadeOnDelete();
                $table->string('workflow_key', 120);
                $table->string('activity_name', 255);
                $table->string('document_type', 120);
                $table->string('status', 24);
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('approving_authority', 180);
                $table->string('approval_reference', 180);
                $table->text('rationale');
                $table->unsignedInteger('version');
                $table->foreignId('evidence_id')->nullable()->constrained('office_tat_approval_evidence')->nullOnDelete();
                $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['protected_area_id', 'workflow_key', 'activity_name', 'document_type', 'version'], 'office_tat_applicability_version_uq');
                $table->index(['protected_area_id', 'effective_from', 'effective_to'], 'office_tat_applicability_bounds_ix');
            });
        }

        if (! Schema::hasTable('office_tat_obligation_schedule_versions')) {
            Schema::create('office_tat_obligation_schedule_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('protected_area_id');
                $table->foreign('protected_area_id', 'office_tat_schedule_pa_fk')->references('id')->on('protected_areas')->cascadeOnDelete();
                $table->string('workflow_key', 120);
                $table->string('activity_name', 255);
                $table->string('document_type', 120);
                $table->unsignedSmallInteger('reporting_year');
                $table->string('period_key', 80);
                $table->string('schedule_kind', 24);
                $table->json('occurrences');
                $table->boolean('inventory_complete')->default(true);
                $table->string('approving_authority', 180);
                $table->string('approval_reference', 180);
                $table->text('rationale');
                $table->foreignId('evidence_id')->nullable()->constrained('office_tat_approval_evidence')->nullOnDelete();
                $table->unsignedInteger('version');
                $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['protected_area_id', 'workflow_key', 'activity_name', 'document_type', 'reporting_year', 'period_key', 'version'], 'office_tat_schedule_version_uq');
                $table->index(['protected_area_id', 'reporting_year', 'period_key'], 'office_tat_schedule_period_ix');
            });
        }

        if (! Schema::hasTable('office_tat_non_receipt_register_versions')) {
            Schema::create('office_tat_non_receipt_register_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('participant_key', 100);
                $table->date('cutoff_date');
                $table->json('obligation_keys');
                $table->json('obligation_manifest');
                $table->string('approving_authority', 180);
                $table->string('approval_reference', 180);
                $table->text('rationale');
                $table->foreignId('evidence_id')->nullable()->constrained('office_tat_approval_evidence')->nullOnDelete();
                $table->unsignedInteger('version');
                $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['participant_key', 'cutoff_date', 'version'], 'office_tat_non_receipt_version_uq');
                $table->index(['participant_key', 'cutoff_date'], 'office_tat_non_receipt_scope_ix');
            });
        }

        if (Schema::hasTable('office_tat_technical_classification_versions')) {
            Schema::table('office_tat_technical_classification_versions', function (Blueprint $table): void {
                foreach (['approving_authority', 'approval_reference', 'rationale', 'effective_from', 'effective_to', 'evidence_id'] as $column) {
                    if ($column !== 'evidence_id' && ! Schema::hasColumn('office_tat_technical_classification_versions', $column)) {
                        match ($column) {
                            'approving_authority' => $table->string($column, 180)->nullable(),
                            'approval_reference' => $table->string($column, 180)->nullable(),
                            'rationale' => $table->text($column)->nullable(),
                            'effective_from', 'effective_to' => $table->date($column)->nullable(),
                            'evidence_id' => null,
                        };
                    }
                }
                if (! Schema::hasColumn('office_tat_technical_classification_versions', 'evidence_id')) {
                    $table->foreignId('evidence_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('office_tat_technical_classification_versions') && Schema::hasColumn('office_tat_technical_classification_versions', 'evidence_id')) {
            Schema::table('office_tat_technical_classification_versions', function (Blueprint $table): void {
                $table->dropColumn(['approving_authority', 'approval_reference', 'rationale', 'effective_from', 'effective_to', 'evidence_id']);
            });
        }
        Schema::dropIfExists('office_tat_non_receipt_register_versions');
        Schema::dropIfExists('office_tat_obligation_schedule_versions');
        Schema::dropIfExists('office_tat_applicability_versions');
        Schema::dropIfExists('office_tat_approval_evidence');
    }
};
