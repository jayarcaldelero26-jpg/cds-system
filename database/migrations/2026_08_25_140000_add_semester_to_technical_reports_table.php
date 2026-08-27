<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_reports', function (Blueprint $table): void {
            $table->string('semester')->nullable()->after('report_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('technical_reports', function (Blueprint $table): void {
            $table->dropIndex(['semester']);
            $table->dropColumn('semester');
        });
    }
};
