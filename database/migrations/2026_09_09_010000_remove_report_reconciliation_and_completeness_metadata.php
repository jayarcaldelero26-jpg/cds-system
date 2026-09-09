<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Child rows must be removed before their reconciliation batches.
        Schema::dropIfExists('report_import_rows');
        Schema::dropIfExists('report_import_batches');

        // requirement_metadata remains the registry's source for schedules and
        // document rules; only the report-completeness checklist is retired.
        if (Schema::hasTable('module_definitions')) {
            DB::table('module_definitions')->orderBy('id')->each(function (object $definition): void {
                $metadata = is_string($definition->requirement_metadata)
                    ? json_decode($definition->requirement_metadata, true)
                    : $definition->requirement_metadata;

                if (! is_array($metadata) || ! array_key_exists('checklist', $metadata)) {
                    return;
                }

                unset($metadata['checklist']);
                DB::table('module_definitions')->where('id', $definition->id)->update([
                    'requirement_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                ]);
            });
        }
    }

    public function down(): void
    {
        // Retired reconciliation tables and checklist metadata are intentionally
        // not recreated by rollback. The cleanup is forward-only by design.
    }
};
