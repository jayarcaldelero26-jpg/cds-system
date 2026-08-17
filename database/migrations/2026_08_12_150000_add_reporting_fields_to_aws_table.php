<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('aws', 'protected_area_id')) {
            Schema::table('aws', function (Blueprint $table) {
                // Nullable preserves existing weather-station rows without assigning or changing data.
                $table->foreignId('protected_area_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('protected_areas')
                    ->nullOnDelete();
            });
        }

        $columns = [
            'report_period_type' => fn (Blueprint $table) => $table->string('report_period_type')->default('Monthly'),
            'start_date' => fn (Blueprint $table) => $table->date('start_date')->nullable(),
            'end_date' => fn (Blueprint $table) => $table->date('end_date')->nullable(),
            'recommendation_remarks' => fn (Blueprint $table) => $table->text('recommendation_remarks')->nullable(),
            'report_file_name' => fn (Blueprint $table) => $table->string('report_file_name')->nullable(),
            'report_file_path' => fn (Blueprint $table) => $table->string('report_file_path')->nullable(),

            // Kolum para sa CSV Raw Data:
            'timestamps' => fn (Blueprint $table) => $table->string('timestamps')->nullable(),
            'precipitation' => fn (Blueprint $table) => $table->decimal('precipitation', 8, 2)->nullable(),
            'wind_direction' => fn (Blueprint $table) => $table->decimal('wind_direction', 8, 2)->nullable(),
            'wind_speed' => fn (Blueprint $table) => $table->decimal('wind_speed', 8, 2)->nullable(),
            'air_temperature' => fn (Blueprint $table) => $table->decimal('air_temperature', 8, 2)->nullable(),
            'relative_humidity' => fn (Blueprint $table) => $table->decimal('relative_humidity', 8, 2)->nullable(),
            'atmospheric_pressure' => fn (Blueprint $table) => $table->decimal('atmospheric_pressure', 8, 2)->nullable(),
            'remarks' => fn (Blueprint $table) => $table->string('remarks')->default('Normal'),
        ];

        foreach ($columns as $column => $addColumn) {
            if (! Schema::hasColumn('aws', $column)) {
                Schema::table('aws', $addColumn);
            }
        }

        // Status is intentionally unchanged so existing station records and values remain intact.
    }

    public function down(): void
    {
        // Deliberately non-destructive: this migration may run on databases with report data.
    }
};
