<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ipaf_revenue_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('reporting_year');
            $table->unsignedTinyInteger('quarter');
            $table->decimal('target_amount', 16, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['protected_area_id', 'reporting_year', 'quarter'], 'ipaf_revenue_targets_pa_year_quarter_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipaf_revenue_targets');
    }
};
