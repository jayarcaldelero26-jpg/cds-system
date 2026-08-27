<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_reports', function (Blueprint $table): void {
            $table->string('target_office')->nullable()->after('protected_area_id');
            $table->string('activity_name')->nullable()->after('report_type');
            $table->string('date_conducted')->nullable()->after('activity_name');
            $table->date('date_accomplished')->nullable()->after('date_conducted');
            $table->date('date_report_released_cenro')->nullable()->after('date_accomplished');
            $table->date('date_endorsed_regional')->nullable()->after('submission_date');
            $table->string('attachment_original_name')->nullable()->after('attachment');
            $table->string('attachment_mime_type')->nullable()->after('attachment_original_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime_type');

            $table->index('target_office');
            $table->index('date_accomplished');
        });
    }

    public function down(): void
    {
        Schema::table('technical_reports', function (Blueprint $table): void {
            $table->dropIndex(['target_office']);
            $table->dropIndex(['date_accomplished']);
            $table->dropColumn([
                'target_office',
                'activity_name',
                'date_conducted',
                'date_accomplished',
                'date_report_released_cenro',
                'date_endorsed_regional',
                'attachment_original_name',
                'attachment_mime_type',
                'attachment_size',
            ]);
        });
    }
};
