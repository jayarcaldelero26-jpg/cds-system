<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_import_rows', function (Blueprint $table): void {
            // Excel worksheet names are limited to 31 characters. A compact
            // column keeps the composite index portable on MySQL while still
            // retaining the complete worksheet name.
            $table->string('source_sheet', 100)->default('')->after('source_period_block');
            $table->dropUnique('report_import_rows_batch_source_unique');
            $table->unique(
                ['report_import_batch_id', 'source_sheet', 'source_row', 'source_period_block'],
                'report_import_rows_batch_sheet_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('report_import_rows', function (Blueprint $table): void {
            $table->dropUnique('report_import_rows_batch_sheet_source_unique');
            $table->unique(
                ['report_import_batch_id', 'source_row', 'source_period_block'],
                'report_import_rows_batch_source_unique'
            );
            $table->dropColumn('source_sheet');
        });
    }
};
