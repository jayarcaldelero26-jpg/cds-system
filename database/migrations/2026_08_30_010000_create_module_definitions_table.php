<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('program_area');
            $table->string('implementation_type');
            $table->string('module_type');
            $table->string('reporting_frequency')->nullable();
            $table->unsignedSmallInteger('plan_duration_years')->nullable();
            $table->string('deadline_mode');
            $table->unsignedSmallInteger('default_deadline_days')->nullable();
            $table->boolean('allow_deadline_override')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->string('existing_route_name')->nullable();
            $table->string('existing_source_key')->nullable();
            $table->unsignedInteger('display_order')->nullable();
            $table->timestamps();

            $table->index(['program_area', 'is_active']);
            $table->index(['implementation_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_definitions');
    }
};
