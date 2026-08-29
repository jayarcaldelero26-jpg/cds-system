<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Date Conducted is descriptive: a single date, range, or multiple-date
     * coverage period. Date Accomplished remains the date used for compliance.
     */
    public function up(): void
    {
        Schema::table('conservation_report_submissions', function (Blueprint $table): void {
            $table->string('date_conducted', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('conservation_report_submissions', function (Blueprint $table): void {
            $table->date('date_conducted')->nullable()->change();
        });
    }
};
