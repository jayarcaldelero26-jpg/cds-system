<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('protected_areas', function (Blueprint $table) {
        $table->longText('spatial_data')->nullable(); // Para ma-igo ang dako nga GeoJSON/JSON
    });
}

public function down(): void
{
    Schema::table('protected_areas', function (Blueprint $table) {
        $table->dropColumn('spatial_data');
    });
}
};
