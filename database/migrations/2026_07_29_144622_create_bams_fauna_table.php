<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bams_fauna', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('fauna_type'); // e.g., herpetofauna, avifauna, mammal, arthropod
            $table->integer('quadrat_no')->nullable();
            $table->integer('transect_no')->nullable();
            $table->string('species');
            $table->string('status_seen_heard')->nullable(); // Heard and/or Seen
            $table->integer('frequency')->nullable();
            $table->decimal('svl', 8, 2)->nullable(); // Snout-Vent Length (mm)
            $table->decimal('t_l', 8, 2)->nullable(); // Tail Length (mm)
            $table->decimal('h_l', 8, 2)->nullable(); // Head Length (mm)
            $table->decimal('f_l', 8, 2)->nullable(); // Forelimb/Foot Length (mm)
            $table->decimal('wt', 8, 2)->nullable();  // Weight (g)
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bams_fauna');
    }
};
