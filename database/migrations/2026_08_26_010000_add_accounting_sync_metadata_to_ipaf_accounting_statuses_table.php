<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ipaf_accounting_statuses', function (Blueprint $table): void {
            $table->string('accounting_data_source')->nullable()->after('bank_balance');
            $table->string('total_ipaf_collection_source_reference')->nullable()->after('accounting_data_source');
        });
    }

    public function down(): void
    {
        Schema::table('ipaf_accounting_statuses', function (Blueprint $table): void {
            $table->dropColumn(['accounting_data_source', 'total_ipaf_collection_source_reference']);
        });
    }
};
