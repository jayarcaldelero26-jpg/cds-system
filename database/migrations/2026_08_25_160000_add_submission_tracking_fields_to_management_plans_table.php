<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('management_plans', function (Blueprint $table): void {
            $table->string('target_office')->nullable()->after('protected_area_id');
            $table->string('activity_name')->nullable()->after('plan_type');
            $table->string('semester', 20)->nullable()->after('activity_name');
            $table->string('date_conducted')->nullable()->after('semester');
            $table->date('date_accomplished')->nullable()->after('date_conducted');
            $table->date('date_report_released_cenro')->nullable()->after('date_accomplished');
            $table->date('date_received_penro')->nullable()->after('date_report_released_cenro');
            $table->date('date_endorsed_regional')->nullable()->after('date_received_penro');

            // Retain legacy values while allowing the submission workflow to omit obsolete inputs.
            $table->string('title')->nullable()->change();
            $table->string('version', 100)->nullable()->change();
            $table->unsignedSmallInteger('prepared_year')->nullable()->change();
            $table->string('status', 50)->nullable()->change();

            $table->index(['semester', 'protected_area_id'], 'management_plans_semester_pa_index');
        });
    }

    public function down(): void
    {
        Schema::table('management_plans', function (Blueprint $table): void {
            $table->dropIndex('management_plans_semester_pa_index');
            $table->dropColumn([
                'target_office',
                'activity_name',
                'semester',
                'date_conducted',
                'date_accomplished',
                'date_report_released_cenro',
                'date_received_penro',
                'date_endorsed_regional',
            ]);
        });
    }
};
