<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_tracking_references', function (Blueprint $table): void {
            $table->timestamp('source_deleted_at')->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_tracking_references', function (Blueprint $table): void {
            $table->dropColumn('source_deleted_at');
        });
    }
};
