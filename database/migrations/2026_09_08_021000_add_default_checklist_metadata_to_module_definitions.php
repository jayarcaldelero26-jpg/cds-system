<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('module_definitions')->orderBy('id')->each(function (object $definition): void {
            $metadata = is_string($definition->requirement_metadata) ? json_decode($definition->requirement_metadata, true) : $definition->requirement_metadata;
            if (! is_array($metadata) || array_key_exists('checklist', $metadata)) return;

            $domain = $definition->requirement_domain ?: ($definition->program_area === 'engp' ? 'engp' : 'pa');
            $key = $definition->requirement_key ?: preg_replace('/^engp_/', '', (string) $definition->code);
            $metadata['checklist'] = $domain === 'engp'
                ? ['mov_required' => true, 'required_fields' => ['office', 'activity_name', 'document_type', 'reporting_year', 'period_key'], 'requires_release' => true, 'requires_penro_receipt' => true]
                : ['mov_required' => true, 'required_fields' => in_array($key, ['regular_pamb', 'special_pamb', 'twc_meetings'], true) ? ['activity_name', 'date_conducted'] : ['activity_name'], 'requires_release' => true, 'requires_penro_receipt' => true];

            DB::table('module_definitions')->where('id', $definition->id)->update(['requirement_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
        });
    }

    public function down(): void
    {
        // Checklist metadata is additive and may contain administrator edits;
        // it is intentionally retained on rollback to avoid data loss.
    }
};
