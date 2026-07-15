<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protected_area_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->text('issue_description');
            $table->text('findings');
            $table->text('recommendations');
            $table->text('actions_taken')->nullable();
            $table->string('status', 50)->default('Pending'); // Pending, Ongoing, Resolved
            $table->date('date_identified');
            $table->date('date_resolved')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['protected_area_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_area_issues');
    }
};
