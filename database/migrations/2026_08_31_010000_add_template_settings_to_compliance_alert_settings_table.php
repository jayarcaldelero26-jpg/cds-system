<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_alert_settings', function (Blueprint $table): void {
            $table->json('template_settings')->nullable()->after('test_recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_alert_settings', function (Blueprint $table): void {
            $table->dropColumn('template_settings');
        });
    }
};