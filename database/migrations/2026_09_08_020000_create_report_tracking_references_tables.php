<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_tracking_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 20);
            $table->unsignedSmallInteger('reporting_year');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['domain', 'reporting_year'], 'report_tracking_sequences_domain_year_unique');
        });

        Schema::create('report_tracking_references', function (Blueprint $table): void {
            $table->id();
            $table->string('tracking_number', 40)->unique();
            $table->string('domain', 20);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->unsignedSmallInteger('reporting_year');
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'report_tracking_references_source_unique');
            $table->index(['domain', 'reporting_year'], 'report_tracking_references_domain_year_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_tracking_references');
        Schema::dropIfExists('report_tracking_sequences');
    }
};
