<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'plot_no' => fn (Blueprint $table) => $table->string('plot_no', 50)->nullable(),
            'transect_no' => fn (Blueprint $table) => $table->unsignedInteger('transect_no')->nullable(),
            'date' => fn (Blueprint $table) => $table->date('date')->nullable(),
            'time' => fn (Blueprint $table) => $table->string('time', 50)->nullable(),
            'observer' => fn (Blueprint $table) => $table->string('observer')->nullable(),
            'vegetation_type' => fn (Blueprint $table) => $table->string('vegetation_type')->nullable(),
            'weather' => fn (Blueprint $table) => $table->string('weather')->nullable(),
            'elevation' => fn (Blueprint $table) => $table->decimal('elevation', 10, 2)->nullable(),
            'gps_unit' => fn (Blueprint $table) => $table->string('gps_unit')->nullable(),
            'lat' => fn (Blueprint $table) => $table->decimal('lat', 10, 7)->nullable(),
            'long' => fn (Blueprint $table) => $table->decimal('long', 10, 7)->nullable(),
            'species_code' => fn (Blueprint $table) => $table->string('species_code')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('bams_flora', $column)) {
                Schema::table('bams_flora', $definition);
            }
        }

        Schema::table('bams_flora', function (Blueprint $table): void {
            $table->integer('tree_no')->nullable()->change();
            $table->string('species')->nullable()->change();
            $table->string('scientific_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve field-entry and legacy data on rollback.
    }
};
