<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_routing_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('document_routing_event_id')->nullable()->constrained('document_routing_events')->nullOnDelete();
            $table->foreignId('pamb_routing_event_id')->nullable()->constrained('pamb_routing_events')->nullOnDelete();
            $table->string('stage_key', 120)->nullable();
            $table->string('action_key', 120)->nullable();
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['source', 'source_id', 'created_at'], 'routing_attachment_source_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_routing_attachments');
    }
};
