<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProtectedAreaFacilitiesTable extends Migration
{
    public function up(): void
    {
        Schema::create('protected_area_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();
            $table->string('facility_type');
            $table->integer('unit_no')->default(1);
            $table->year('year_established')->nullable();
            $table->string('location_brgy_muni')->nullable();
            $table->string('management_zone')->nullable(); // MUZ, SPZ
            $table->string('within_easement_zone')->nullable();
            $table->string('coordinates')->nullable();
            $table->string('source_of_fund')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('Functional');
            $table->string('typhoon_affected')->nullable();
            $table->string('tenurial_instrument')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_area_facilities');
    }
}
