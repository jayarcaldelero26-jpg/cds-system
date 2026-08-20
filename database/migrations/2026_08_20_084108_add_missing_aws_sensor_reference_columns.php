<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('aws', 'port2_precipitation')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('port2_precipitation', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'port2_max_precipitation_rate')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('port2_max_precipitation_rate', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'port3_water_content')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('port3_water_content', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'port3_soil_temperature')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('port3_soil_temperature', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'port3_ec')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('port3_ec', 8, 3)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'rainfall_difference_mm')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('rainfall_difference_mm', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'rainfall_difference_percent')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->decimal('rainfall_difference_percent', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'rainfall_crosscheck_days')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->unsignedInteger('rainfall_crosscheck_days')->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'rainfall_crosscheck_status')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->string('rainfall_crosscheck_status')->nullable();
            });
        }

        if (! Schema::hasColumn('aws', 'soil_condition_context')) {
            Schema::table('aws', function (Blueprint $table) {
                $table->string('soil_condition_context')->nullable();
            });
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
