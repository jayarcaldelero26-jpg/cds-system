<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pamb_routing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conservation_report_submission_id')
                ->constrained('conservation_report_submissions')
                ->cascadeOnDelete();
            $table->string('workflow_key', 80);
            $table->string('stage_key', 80);
            $table->dateTime('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['conservation_report_submission_id', 'stage_key'], 'pamb_routing_events_submission_stage_unique');
            $table->index(['workflow_key', 'stage_key'], 'pamb_routing_events_workflow_stage_index');
            $table->index(['occurred_at'], 'pamb_routing_events_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pamb_routing_events');
    }
};
