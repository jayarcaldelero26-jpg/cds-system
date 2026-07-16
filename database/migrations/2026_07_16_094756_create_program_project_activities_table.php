<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category'); // Program, Project, Activity
            $table->text('description')->nullable();
            $table->decimal('budget', 15, 2)->default(0.00);
            $table->string('source_of_fund')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('Ongoing'); // Proposed, Ongoing, Completed, Terminated
            $table->text('remarks')->nullable();
            $table->string('attachment')->nullable(); // PDF o docs
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_project_activities');
    }
};
