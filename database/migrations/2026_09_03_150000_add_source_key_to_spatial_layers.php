<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spatial_layers', function (Blueprint $table): void {
            $table->string('source_key', 40)->nullable()->after('protected_area_id');
            $table->index(['source_key', 'protected_area_id'], 'spatial_layers_source_pa_idx');
        });
    }

    public function down(): void
    {
        Schema::table('spatial_layers', function (Blueprint $table): void {
            $table->dropIndex('spatial_layers_source_pa_idx');
            $table->dropColumn('source_key');
        });
    }
};
