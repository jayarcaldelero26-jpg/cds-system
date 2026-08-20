<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protected_areas', function (Blueprint $table): void {
            $table->decimal('core_zone_hectares', 14, 2)
                ->nullable()
                ->after('area_hectares');

            $table->decimal('buffer_zone_hectares', 14, 2)
                ->nullable()
                ->after('core_zone_hectares');
        });
    }

    public function down(): void
    {
        Schema::table('protected_areas', function (Blueprint $table): void {
            $table->dropColumn([
                'core_zone_hectares',
                'buffer_zone_hectares',
            ]);
        });
    }
};
