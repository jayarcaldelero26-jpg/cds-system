<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aws', function (Blueprint $table): void {
            $table->string('activity_name')->nullable()->after('location');
            $table->string('document_type')->nullable()->after('report_period_type');
            $table->string('semester')->nullable()->after('document_type')->index();
            $table->string('date_conducted')->nullable()->after('semester');
            $table->date('date_accomplished')->nullable()->after('date_conducted')->index();
            $table->date('date_report_released_cenro')->nullable()->after('date_accomplished');
            $table->date('date_received_penro')->nullable()->after('date_report_released_cenro');
            $table->date('date_endorsed_regional')->nullable()->after('date_received_penro');
        });
    }

    public function down(): void
    {
        Schema::table('aws', function (Blueprint $table): void {
            $table->dropIndex(['semester']);
            $table->dropIndex(['date_accomplished']);
            $table->dropColumn(['activity_name', 'document_type', 'semester', 'date_conducted', 'date_accomplished', 'date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional']);
        });
    }
};
