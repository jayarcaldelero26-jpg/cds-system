<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environmental_compliances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->enum('certificate_type', ['ECC', 'CNC']);
            $table->string('certificate_number');
            $table->date('issuance_date');
            $table->string('status', 50); // e.g., Active, Expired, Suspended
            $table->string('attachment')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // GImuboan ang ngalan sa index aron dili molapas sa 64 characters sa MySQL
            $table->index(['protected_area_id', 'certificate_type'], 'env_compliances_pa_cert_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environmental_compliances');
    }
};
