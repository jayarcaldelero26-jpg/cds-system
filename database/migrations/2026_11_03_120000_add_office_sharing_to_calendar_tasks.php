<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_tasks') || Schema::hasColumn('calendar_tasks', 'visibility')) {
            throw new RuntimeException('Calendar task sharing migration precondition failed.');
        }

        Schema::table('calendar_tasks', function (Blueprint $table): void {
            $table->string('visibility', 20)->default('private')->after('status');
            $table->index(['visibility', 'scheduled_date'], 'calendar_tasks_visibility_date_idx');
        });

        Schema::create('calendar_task_audiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_task_id')->constrained('calendar_tasks')->cascadeOnDelete();
            $table->string('audience_type', 20);
            // These identities intentionally have no FK: office maintenance or removal
            // must not delete a task or prevent ordinary office administration.
            $table->unsignedBigInteger('organizational_office_id')->nullable();
            $table->unsignedBigInteger('protected_area_id')->nullable();
            $table->string('office_type_snapshot', 30)->nullable();
            $table->string('office_code_snapshot', 80)->nullable();
            $table->string('office_name_snapshot', 150)->nullable();
            $table->string('protected_area_name_snapshot', 255)->nullable();
            $table->string('label_snapshot', 255);
            $table->timestamps();
            $table->unique(['calendar_task_id', 'audience_type'], 'calendar_task_audiences_task_type_unique');
            $table->index(
                ['organizational_office_id', 'audience_type', 'protected_area_id', 'calendar_task_id'],
                'calendar_task_audiences_office_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_task_audiences');

        if (Schema::hasTable('calendar_tasks') && Schema::hasColumn('calendar_tasks', 'visibility')) {
            Schema::table('calendar_tasks', function (Blueprint $table): void {
                $table->dropIndex('calendar_tasks_visibility_date_idx');
                $table->dropColumn('visibility');
            });
        }
    }
};
