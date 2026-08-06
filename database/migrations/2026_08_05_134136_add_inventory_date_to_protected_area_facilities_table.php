<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInventoryDateToProtectedAreaFacilitiesTable extends Migration
{
    public function up(): void
    {
        Schema::table('protected_area_facilities', function (Blueprint $table) {
            $table->string('inventory_date')->nullable()->after('protected_area_id'); // Pwede day o year/period tulad sa "July 2022" o "2026"
        });
    }

    public function down(): void
    {
        Schema::table('protected_area_facilities', function (Blueprint $table) {
            $table->dropColumn('inventory_date');
        });
    }
}
