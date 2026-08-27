<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_compliance_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->morphs('source');
            $table->timestamp('confirmed_at');
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'report_compliance_confirmations_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_compliance_confirmations');
    }
};
