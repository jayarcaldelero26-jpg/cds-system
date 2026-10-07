<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_routing_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 80);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('setting_version_id')->constrained('routing_position_setting_versions')->restrictOnDelete();
            $table->string('profile', 40);
            $table->string('graph_version', 40);
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->unique(['source_key', 'source_id']);
            $table->index(['source_key', 'setting_version_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Route snapshots are retained for compatibility-aware rollback; use a forward fix instead of dropping captured routes.');
    }
};
