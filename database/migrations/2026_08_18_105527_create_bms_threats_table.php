<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bms_threats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->nullable()->constrained('protected_areas')->nullOnDelete();

            $table->date('date');
            $table->string('location')->nullable();

            $table->string('threat_type');
            $table->string('threat_detail')->nullable();
            $table->string('extent')->nullable();
            $table->string('severity')->nullable();

            // Coordinate input format ug ang tanang posible nga values
            // (parehas ra sa pattern sa BMS Records: DD / DMS / UTM)
            $table->string('coord_format')->default('DD');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->string('lat_deg')->nullable();
            $table->string('lat_min')->nullable();
            $table->string('lat_sec')->nullable();
            $table->string('long_deg')->nullable();
            $table->string('long_min')->nullable();
            $table->string('long_sec')->nullable();
            $table->string('utm_zone')->nullable();
            $table->string('easting')->nullable();
            $table->string('northing')->nullable();

            $table->string('actions_taken')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bms_threats');
    }
};
