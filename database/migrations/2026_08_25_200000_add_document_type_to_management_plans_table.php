<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('management_plans', function (Blueprint $table): void {
            $table->string('document_type')->nullable()->after('activity_name');
        });
    }

    public function down(): void
    {
        Schema::table('management_plans', function (Blueprint $table): void {
            $table->dropColumn('document_type');
        });
    }
};
