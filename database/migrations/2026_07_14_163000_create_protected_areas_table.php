<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('category');
            $table->string('municipality');
            $table->string('province');
            $table->string('region');
            $table->decimal('area_hectares', 14, 2)->nullable();
            $table->string('pamo')->nullable();
            $table->string('pasu')->nullable();
            $table->unsignedSmallInteger('year_established')->nullable();
            $table->string('legal_basis')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('Active');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
            $table->index('municipality');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_areas');
    }
};
