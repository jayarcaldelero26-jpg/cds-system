<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->date('scheduled_date');
            $table->boolean('is_all_day')->default(true);
            // Store local wall-clock values as HH:mm text. No UTC conversion is applied.
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
            $table->index(['created_by', 'scheduled_date'], 'calendar_tasks_creator_date_idx');
            $table->index(['created_by', 'status'], 'calendar_tasks_creator_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_tasks');
    }
};
