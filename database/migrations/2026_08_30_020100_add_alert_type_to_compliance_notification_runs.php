<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compliance_notification_runs', 'alert_type')) {
            Schema::table('compliance_notification_runs', function (Blueprint $table): void {
                $table->string('alert_type', 20)->nullable()->after('idempotency_key');
                $table->index(['alert_type', 'run_date'], 'compliance_notification_runs_alert_lookup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('compliance_notification_runs', 'alert_type')) {
            Schema::table('compliance_notification_runs', function (Blueprint $table): void {
                $table->dropIndex('compliance_notification_runs_alert_lookup');
                $table->dropColumn('alert_type');
            });
        }
    }
};
