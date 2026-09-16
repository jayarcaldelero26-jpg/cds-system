<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'target_office' => fn (Blueprint $table) => $table->string('target_office')->nullable()->index(),
            'reporting_year' => fn (Blueprint $table) => $table->unsignedSmallInteger('reporting_year')->nullable()->index(),
            'quarter' => fn (Blueprint $table) => $table->unsignedTinyInteger('quarter')->nullable()->index(),
            'monitoring_period_start' => fn (Blueprint $table) => $table->date('monitoring_period_start')->nullable(),
            'monitoring_period_end' => fn (Blueprint $table) => $table->date('monitoring_period_end')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('aws', $name)) {
                Schema::table('aws', $definition);
            }
        }
    }

    public function down(): void
    {
        // Forward-only compatibility migration: report data must not be lost.
    }
};
