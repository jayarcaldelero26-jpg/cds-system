<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ipaf_accounting_statuses', function (Blueprint $table): void {
            $table->string('bank_balance_source')->nullable()->after('bank_balance');
            $table->string('bank_balance_source_reference')->nullable()->after('bank_balance_source');
            $table->timestamp('bank_balance_synced_at')->nullable()->after('bank_balance_source_reference');
            $table->string('bank_balance_sync_status')->nullable()->after('bank_balance_synced_at');
            $table->date('bank_balance_source_as_of')->nullable()->after('bank_balance_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('ipaf_accounting_statuses', function (Blueprint $table): void {
            $table->dropColumn(['bank_balance_source', 'bank_balance_source_reference', 'bank_balance_synced_at', 'bank_balance_sync_status', 'bank_balance_source_as_of']);
        });
    }
};
