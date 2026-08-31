<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_routing_corrections', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('field', 120);
            $table->dateTime('original_value')->nullable();
            $table->dateTime('corrected_value')->nullable();
            $table->text('reason');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('corrected_at');
            $table->index(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_routing_corrections');
    }
};
