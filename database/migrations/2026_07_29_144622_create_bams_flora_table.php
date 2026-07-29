<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bams_flora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->nullable()->constrained()->onDelete('cascade');
            $table->integer('quadrat_no');
            $table->integer('tree_no');
            $table->string('species');
            $table->string('scientific_name');
            $table->string('family_name')->nullable();
            $table->decimal('dbh', 8, 2)->nullable(); // Diameter at Breast Height (cm)
            $table->decimal('th', 8, 2)->nullable();  // Total Height (m)
            $table->decimal('mh', 8, 2)->nullable();  // Merchantable Height (m)
            $table->string('bearing')->nullable();    // e.g. N 67° E
            $table->decimal('distance', 8, 2)->nullable(); // Distance (m)
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bams_flora');
    }
};
