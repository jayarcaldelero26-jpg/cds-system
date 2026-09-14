<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_import_batches', function (Blueprint $table): void {
            $table->string('workbook_type', 60)->nullable()->after('file_type');
            $table->json('detected_sheets')->nullable()->after('workbook_type');
            $table->json('ignored_sheets')->nullable()->after('detected_sheets');
            $table->json('unsupported_sheets')->nullable()->after('ignored_sheets');
            $table->json('warnings')->nullable()->after('unsupported_sheets');
        });

        Schema::table('report_import_rows', function (Blueprint $table): void {
            $table->string('source_period_block', 255)->default('')->after('source_row');
            $table->dropUnique('report_import_rows_batch_row_unique');
            $table->unique(['report_import_batch_id', 'source_row', 'source_period_block'], 'report_import_rows_batch_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('report_import_rows', function (Blueprint $table): void {
            $table->dropUnique('report_import_rows_batch_source_unique');
            $table->unique(['report_import_batch_id', 'source_row'], 'report_import_rows_batch_row_unique');
            $table->dropColumn('source_period_block');
        });
        Schema::table('report_import_batches', function (Blueprint $table): void {
            $table->dropColumn(['workbook_type', 'detected_sheets', 'ignored_sheets', 'unsupported_sheets', 'warnings']);
        });
    }
};
