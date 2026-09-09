<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_number', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename', 255);
            $table->string('file_type', 10);
            $table->string('domain', 20)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('new_valid_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('unauthorized_count')->default(0);
            $table->unsignedInteger('manual_review_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('expected_missing_count')->default(0);
            $table->json('expected_missing')->nullable();
            $table->string('status', 30)->default('preview');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->json('raw_data');
            $table->string('status', 30);
            $table->string('canonical_identity', 255)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('requirement_definition_id')->nullable();
            $table->json('system_values')->nullable();
            $table->json('issues')->nullable();
            $table->timestamps();
            $table->unique(['report_import_batch_id', 'source_row'], 'report_import_rows_batch_row_unique');
            $table->index(['report_import_batch_id', 'status'], 'report_import_rows_batch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_import_rows');
        Schema::dropIfExists('report_import_batches');
    }
};
