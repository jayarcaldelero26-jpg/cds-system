<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('protected_areas', 'core_zone_hectares')) {
            Schema::table('protected_areas', function (Blueprint $table): void {
                $table->decimal('core_zone_hectares', 14, 2)
                    ->nullable()
                    ->after('area_hectares');
            });
        }

        if (! Schema::hasColumn('protected_areas', 'buffer_zone_hectares')) {
            Schema::table('protected_areas', function (Blueprint $table): void {
                $table->decimal('buffer_zone_hectares', 14, 2)
                    ->nullable()
                    ->after('core_zone_hectares');
            });
        }
    }

    public function down(): void
    {
        // These columns are part of the original protected_areas table schema.
        // Keep rollback non-destructive for databases where this compatibility
        // migration did not create them.
    }
};
