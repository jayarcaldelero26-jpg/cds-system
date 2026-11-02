<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_tasks', function (Blueprint $table): void {
            $table->date('scheduled_end_date')->nullable()->after('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_tasks', function (Blueprint $table): void {
            $table->dropColumn('scheduled_end_date');
        });
    }
};
