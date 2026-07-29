<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bms_records', function (Blueprint $table) {
            $table->string('location')->nullable();
            $table->string('length_of_transect')->nullable();
            $table->string('weather_condition')->nullable();
            $table->string('ecosystem_type')->nullable();
            $table->string('mode_of_observation')->nullable();
        });
    }

    public function down()
    {
        Schema::table('bms_records', function (Blueprint $table) {
            $table->dropColumn([
                'location', 'length_of_transect', 'weather_condition',
                'ecosystem_type', 'mode_of_observation'
            ]);
        });
    }
};
