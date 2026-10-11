<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $sourceTables = [
        'conservation_report_submissions',
        'engp_report_submissions',
        'bms_report_submissions',
        'bams_report_submissions',
        'imea_report_submissions',
        'imea_facility_maintenance_reports',
        'aws',
        'ipaf_management_reports',
        'ipaf_revenue_collections',
        'management_plans',
    ];

    public function up(): void
    {
        foreach ($this->sourceTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'is_historical')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('is_historical')->default(false)->index();
            });
        }

        if (! Schema::hasTable('historical_report_origins')) {
            Schema::create('historical_report_origins', function (Blueprint $table): void {
                $table->id();
                $table->string('source', 64);
                $table->unsignedBigInteger('source_record_id');
                $table->string('workflow_key', 120)->nullable();
                $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('idempotency_key')->unique();
                $table->json('original_milestones')->nullable();
                $table->timestampTz('encoded_at');
                $table->timestamps();
                $table->unique(['source', 'source_record_id']);
                $table->index(['source', 'workflow_key', 'encoded_at']);
            });
        }

        if (! Schema::hasTable('office_tat_configuration_versions')) {
            Schema::create('office_tat_configuration_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('office_category', 16);
                $table->string('office', 160);
                $table->string('period_key', 16);
                $table->unsignedInteger('volume_target')->nullable();
                $table->json('score_weights');
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['office_category', 'office', 'period_key', 'version'], 'office_tat_config_version_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('office_tat_configuration_versions');
        Schema::dropIfExists('historical_report_origins');

        foreach (array_reverse($this->sourceTables) as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'is_historical')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('is_historical');
                });
            }
        }
    }
};
