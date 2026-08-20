<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'port2_precipitation' => fn (Blueprint $table) => $table->decimal('port2_precipitation', 8, 2)->nullable(),
            'port2_max_precipitation_rate' => fn (Blueprint $table) => $table->decimal('port2_max_precipitation_rate', 8, 2)->nullable(),
            'port3_water_content' => fn (Blueprint $table) => $table->decimal('port3_water_content', 8, 2)->nullable(),
            'port3_soil_temperature' => fn (Blueprint $table) => $table->decimal('port3_soil_temperature', 8, 2)->nullable(),
            'port3_ec' => fn (Blueprint $table) => $table->decimal('port3_ec', 8, 3)->nullable(),
            'rainfall_difference_mm' => fn (Blueprint $table) => $table->decimal('rainfall_difference_mm', 8, 2)->nullable(),
            'rainfall_difference_percent' => fn (Blueprint $table) => $table->decimal('rainfall_difference_percent', 8, 2)->nullable(),
            'rainfall_crosscheck_days' => fn (Blueprint $table) => $table->unsignedInteger('rainfall_crosscheck_days')->nullable(),
            'rainfall_crosscheck_status' => fn (Blueprint $table) => $table->string('rainfall_crosscheck_status')->nullable(),
            'soil_condition_context' => fn (Blueprint $table) => $table->string('soil_condition_context')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('aws', $column)) {
                Schema::table('aws', $definition);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'port2_precipitation',
            'port2_max_precipitation_rate',
            'port3_water_content',
            'port3_soil_temperature',
            'port3_ec',
            'rainfall_difference_mm',
            'rainfall_difference_percent',
            'rainfall_crosscheck_days',
            'rainfall_crosscheck_status',
            'soil_condition_context',
        ] as $column) {
            if (Schema::hasColumn('aws', $column)) {
                Schema::table('aws', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
