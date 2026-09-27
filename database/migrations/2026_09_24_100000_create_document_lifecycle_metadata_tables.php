<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachment_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('logical_slot', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('remarks')->nullable();
            $table->string('old_path', 500)->nullable();
            $table->string('old_filename')->nullable();
            $table->char('old_sha256', 64)->nullable();
            $table->unsignedBigInteger('old_size')->nullable();
            $table->string('new_path', 500)->nullable();
            $table->string('new_filename')->nullable();
            $table->char('new_sha256', 64)->nullable();
            $table->unsignedBigInteger('new_size')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id', 'logical_slot'], 'document_attachment_history_slot_index');
        });

        Schema::create('document_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('logical_slot', 80);
            $table->string('google_drive_file_id')->nullable();
            $table->string('google_drive_folder_id')->nullable();
            $table->char('archived_sha256', 64)->nullable();
            $table->unsignedBigInteger('archived_size')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_status', 16)->default('PENDING');
            $table->string('original_filename')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'logical_slot'], 'document_archive_logical_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_archives');
        Schema::dropIfExists('document_attachment_histories');
    }
};
