<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aws_observations')) {
            Schema::create('aws_observations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_aws_id')->nullable()->unique();
                $table->foreignId('protected_area_id')->nullable()->constrained('protected_areas')->nullOnDelete();
                $table->string('station_name')->nullable();
                $table->string('location')->nullable();
                $table->string('status')->nullable();
                $table->string('report_period_type')->nullable();
                $table->date('start_date')->nullable()->index();
                $table->date('end_date')->nullable();
                $table->string('timestamps')->nullable();
                $table->decimal('precipitation', 8, 2)->nullable();
                $table->string('wind_direction')->nullable();
                $table->decimal('wind_speed', 8, 2)->nullable();
                $table->decimal('air_temperature', 8, 2)->nullable();
                $table->decimal('relative_humidity', 8, 2)->nullable();
                $table->decimal('atmospheric_pressure', 8, 2)->nullable();
                $table->string('remarks')->nullable();
                $table->unsignedSmallInteger('observation_count')->nullable();
                $table->unsignedSmallInteger('expected_observations')->nullable();
                $table->decimal('data_completeness', 5, 1)->nullable();
                $table->decimal('port2_precipitation', 8, 2)->nullable();
                $table->decimal('port2_max_precipitation_rate', 8, 2)->nullable();
                $table->decimal('port3_water_content', 8, 2)->nullable();
                $table->decimal('port3_soil_temperature', 8, 2)->nullable();
                $table->decimal('port3_ec', 8, 3)->nullable();
                $table->decimal('rainfall_difference_mm', 8, 2)->nullable();
                $table->decimal('rainfall_difference_percent', 8, 2)->nullable();
                $table->unsignedSmallInteger('rainfall_crosscheck_days')->nullable();
                $table->string('rainfall_crosscheck_status')->nullable();
                $table->string('soil_condition_context')->nullable();
                $table->timestamps();
            });
        }

        $columns = [
            'legacy_aws_id', 'protected_area_id', 'station_name', 'location', 'status',
            'report_period_type', 'start_date', 'end_date', 'timestamps',
            'precipitation', 'wind_direction', 'wind_speed', 'air_temperature',
            'relative_humidity', 'atmospheric_pressure', 'remarks',
            'observation_count', 'expected_observations', 'data_completeness',
            'port2_precipitation', 'port2_max_precipitation_rate', 'port3_water_content',
            'port3_soil_temperature', 'port3_ec', 'rainfall_difference_mm',
            'rainfall_difference_percent', 'rainfall_crosscheck_days',
            'rainfall_crosscheck_status', 'soil_condition_context', 'created_at', 'updated_at',
        ];

        DB::table('aws')->whereNotNull('timestamps')->orderBy('id')->chunkById(250, function ($rows) use ($columns): void {
            $payload = $rows->map(function ($row) use ($columns): array {
                $values = (array) $row;
                $values['legacy_aws_id'] = $values['id'];
                unset($values['id']);
                return collect($columns)->mapWithKeys(fn (string $column) => [$column => $values[$column] ?? null])->all();
            })->all();
            if ($payload !== []) DB::table('aws_observations')->insertOrIgnore($payload);
        });
    }

    public function down(): void
    {
        // No destructive rollback: copied observation data is retained.
    }
};
