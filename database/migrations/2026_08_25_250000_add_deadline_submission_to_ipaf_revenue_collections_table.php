<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ipaf_revenue_collections', function (Blueprint $table): void {
            $table->date('deadline_submission')->nullable()->after('date_received_penro');
        });
    }

    public function down(): void
    {
        Schema::table('ipaf_revenue_collections', function (Blueprint $table): void {
            $table->dropColumn('deadline_submission');
        });
    }
};
