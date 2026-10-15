<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_archives', function (Blueprint $table): void {
            $table->string('archive_phase', 48)->default('dispatch_checkpoint')->index();
        });

        Schema::create('final_document_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('workflow_key', 100);
            $table->string('release_evidence_type', 80);
            $table->string('release_evidence_key', 160);
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('receiving_category', 80);
            $table->string('receiving_office', 190);
            $table->timestamp('physically_received_at');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'final_document_receipt_source_unique');
            $table->index(['source_type', 'source_id', 'release_evidence_key'], 'final_document_receipt_evidence_index');
        });

        Schema::create('final_document_archive_manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('final_document_receipts')->restrictOnDelete();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('workflow_key', 100);
            $table->string('archive_phase', 48)->default('regional_return_final');
            $table->unsignedInteger('revision');
            $table->string('intent_key', 36)->unique();
            $table->string('archive_status', 16)->default('PENDING');
            $table->boolean('signature_confirmation')->default(false);
            $table->char('signature_set_sha256', 64);
            $table->foreignId('signature_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signature_confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->string('claim_token', 36)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['receipt_id', 'revision'], 'final_document_manifest_revision_unique');
            $table->index(['source_type', 'source_id', 'archive_phase', 'archive_status'], 'final_document_manifest_scope_index');
        });

        Schema::create('final_document_archive_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manifest_id')->constrained('final_document_archive_manifests')->restrictOnDelete();
            $table->string('logical_identity', 100);
            $table->unsignedSmallInteger('ordinal');
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->string('staged_path', 500);
            $table->string('file_status', 16)->default('PENDING');
            $table->foreignId('document_archive_id')->nullable()->constrained('document_archives')->nullOnDelete();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('claim_token', 36)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['manifest_id', 'logical_identity'], 'final_document_manifest_file_identity_unique');
            $table->unique(['manifest_id', 'ordinal'], 'final_document_manifest_file_ordinal_unique');
            $table->index(['manifest_id', 'file_status'], 'final_document_manifest_file_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_document_archive_files');
        Schema::dropIfExists('final_document_archive_manifests');
        Schema::dropIfExists('final_document_receipts');
        Schema::table('document_archives', function (Blueprint $table): void {
            $table->dropIndex(['archive_phase']);
            $table->dropColumn('archive_phase');
        });
    }
};
