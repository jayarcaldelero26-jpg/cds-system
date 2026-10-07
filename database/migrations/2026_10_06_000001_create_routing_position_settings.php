<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_position_setting_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('version')->unique();
            $table->boolean('office_penro_enabled');
            $table->boolean('penro_tsd_chief_enabled');
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saved_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('routing_position_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('setting_version_id')->constrained('routing_position_setting_versions')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('routing_position_cutover_watermarks', function (Blueprint $table): void {
            $table->string('source_key', 80)->primary();
            $table->string('table_name', 120);
            $table->unsignedBigInteger('max_id')->default(0);
            $table->timestamp('captured_at');
        });

        $now = now();
        $versionId = DB::table('routing_position_setting_versions')->insertGetId([
            'version' => 1,
            'office_penro_enabled' => true,
            'penro_tsd_chief_enabled' => true,
            'saved_by' => null,
            'saved_at' => $now,
            'reason' => 'Initial all-enabled baseline',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('routing_position_settings')->insert(['id' => 1, 'setting_version_id' => $versionId, 'created_at' => $now, 'updated_at' => $now]);

        $sources = [
            'conservation' => 'conservation_report_submissions',
            'engp' => 'engp_report_submissions',
            'bms' => 'bms_report_submissions',
            'bams' => 'bams_report_submissions',
            'imea' => 'imea_report_submissions',
            'imea-maintenance' => 'imea_facility_maintenance_reports',
            'aws' => 'aws',
            'ipaf-management' => 'ipaf_management_reports',
            'revenue' => 'ipaf_revenue_collections',
            'management-plans' => 'management_plans',
        ];
        foreach ($sources as $source => $tableName) {
            if (! Schema::hasTable($tableName)) {
                throw new RuntimeException("Cannot capture routing cutover watermark: missing {$tableName}.");
            }
            DB::table('routing_position_cutover_watermarks')->insert([
                'source_key' => $source,
                'table_name' => $tableName,
                'max_id' => (int) (DB::table($tableName)->max('id') ?? 0),
                'captured_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Routing position history is retained for compatibility-aware rollback; use a forward fix instead of dropping snapshots or setting versions.');
    }
};
