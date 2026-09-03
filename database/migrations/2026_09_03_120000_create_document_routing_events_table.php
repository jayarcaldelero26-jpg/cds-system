<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_routing_events')) return;

        Schema::create('document_routing_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('workflow_key', 120)->nullable();
            $table->string('event_key', 80);
            $table->string('from_stage', 100);
            $table->string('to_stage', 100);
            $table->string('from_office', 160)->nullable();
            $table->string('to_office', 160)->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'document_routing_source_index');
            $table->index('workflow_key', 'document_routing_workflow_index');
            $table->index('event_key', 'document_routing_event_index');
            $table->index('occurred_at', 'document_routing_occurred_index');
            $table->index('recorded_by', 'document_routing_recorded_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routing_events');
    }
};
