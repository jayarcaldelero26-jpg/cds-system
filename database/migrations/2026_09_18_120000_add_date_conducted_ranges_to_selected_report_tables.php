<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // date_conducted remains a compatibility anchor; structured ranges hold the complete activity dates.
    /** @var list<string> */
    private array $tables = ['bms_report_submissions', 'bams_report_submissions', 'imea_report_submissions', 'conservation_report_submissions'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasColumn($tableName, 'date_conducted_ranges')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->json('date_conducted_ranges')->nullable()->after('date_conducted');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasColumn($tableName, 'date_conducted_ranges')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('date_conducted_ranges');
                });
            }
        }
    }
};
