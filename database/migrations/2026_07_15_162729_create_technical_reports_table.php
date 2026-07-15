<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_reports', function (Blueprint $table) {
            $table->id();
            // Konektado sa Protected Area
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();

            // Detalye sa Report
            $table->string('report_type'); // AWS, Biodiversity Assessment, etc.
            $table->integer('reporting_year');
            $table->string('quarter')->nullable(); // Q1, Q2, Q3, Q4, or Annual
            $table->date('submission_date')->nullable();

            // Status ug File
            $table->string('status')->default('Pending'); // Submitted, Pending, Delayed
            $table->string('attachment')->nullable(); // PDF file path
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_reports');
    }
};
