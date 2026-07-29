<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bms_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->constrained('protected_areas')->onDelete('cascade');
            $table->date('monitoring_date');
            $table->string('station')->nullable();
            $table->string('time')->nullable();
            $table->string('category')->nullable();
            $table->string('taxonomic_group');
            $table->string('species_common_name')->nullable();
            $table->string('species_scientific_name');
            // Gihimo natong string para pwede butangan og text (e.g., "Abundant", "10")
            $table->string('count')->nullable();
            $table->string('observer_name')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->string('elevation')->nullable();
            $table->string('attachment')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bms_records');
    }
};
