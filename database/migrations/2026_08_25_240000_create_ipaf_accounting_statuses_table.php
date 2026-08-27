<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ipaf_accounting_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('reporting_year');
            $table->decimal('total_ipaf_collection', 16, 2)->nullable();
            $table->decimal('bank_balance', 16, 2)->nullable();
            $table->text('status_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['protected_area_id', 'reporting_year'], 'ipaf_accounting_statuses_pa_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipaf_accounting_statuses');
    }
};
