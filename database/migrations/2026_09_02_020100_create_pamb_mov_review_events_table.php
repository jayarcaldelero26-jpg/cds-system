<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pamb_mov_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conservation_report_submission_id')->constrained('conservation_report_submissions')->cascadeOnDelete();
            $table->string('event_key', 50);
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['conservation_report_submission_id', 'event_key'], 'pamb_mov_review_events_submission_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pamb_mov_review_events');
    }
};
