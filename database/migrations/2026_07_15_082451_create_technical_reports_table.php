<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->string('report_type'); // e.g., "AWS", "Biodiversity Monitoring"
            $table->date('due_date')->nullable();
            $table->date('submission_date')->nullable();
            $table->string('status', 50); // e.g., Submitted, Pending, Late
            $table->text('recommendations')->nullable();
            $table->string('attachment')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['protected_area_id', 'report_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_reports');
    }
};
