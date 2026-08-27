<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->string('attention_line')->nullable()->after('recipient_name');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->dropColumn('attention_line');
        });
    }
};
