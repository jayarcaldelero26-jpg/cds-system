<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained()->restrictOnDelete();
            $table->string('plan_type', 50);
            $table->string('title');
            $table->string('version', 100);
            $table->unsignedSmallInteger('prepared_year');
            $table->date('approval_date')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 50);
            $table->text('remarks')->nullable();
            $table->string('attachment')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['protected_area_id', 'plan_type']);
            $table->index(['status', 'prepared_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_plans');
    }
};
