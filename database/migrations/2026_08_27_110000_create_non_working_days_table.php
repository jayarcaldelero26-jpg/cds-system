<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('non_working_days', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->string('type', 64);
            $table->string('scope', 32);
            $table->string('location')->default('');
            $table->string('reference')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['date', 'scope', 'location'], 'non_working_days_date_scope_location_unique');
            $table->index(['date', 'is_active'], 'non_working_days_date_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_working_days');
    }
};
