<?php

use App\Domain\Modules\ProgramArea;
use App\Models\ModuleDefinition;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('module_definitions'))->pluck('name')->all();

        Schema::table('module_definitions', function (Blueprint $table) use ($indexes): void {
            if (! Schema::hasColumn('module_definitions', 'requirement_domain')) $table->string('requirement_domain')->nullable()->after('program_area');
            if (! Schema::hasColumn('module_definitions', 'requirement_key')) $table->string('requirement_key')->nullable()->after('code');
            if (! Schema::hasColumn('module_definitions', 'requirement_metadata')) $table->json('requirement_metadata')->nullable()->after('description');
            if (! Schema::hasColumn('module_definitions', 'effective_from')) $table->date('effective_from')->nullable()->after('is_active');
            if (! Schema::hasColumn('module_definitions', 'effective_to')) $table->date('effective_to')->nullable()->after('effective_from');
            if (! Schema::hasColumn('module_definitions', 'first_applicable_year')) $table->unsignedSmallInteger('first_applicable_year')->nullable()->after('effective_to');
            if (! in_array('module_definitions_requirement_domain_is_active_index', $indexes, true)) $table->index(['requirement_domain', 'is_active']);
            if (! in_array('module_definitions_requirement_domain_requirement_key_index', $indexes, true)) $table->index(['requirement_domain', 'requirement_key']);
        });

        $conservation = app(ConservationReportWorkflowRegistry::class);
        $engp = app(EngpReportWorkflowRegistry::class);

        ModuleDefinition::query()->eachById(function (ModuleDefinition $definition) use ($conservation, $engp): void {
            $isEngp = $definition->program_area === ProgramArea::ENGP;
            $key = $isEngp && str_starts_with((string) $definition->code, 'engp_')
                ? substr((string) $definition->code, 5)
                : (string) $definition->code;
            $metadata = $isEngp ? $engp->defaultFind($key) : $conservation->defaultFind($key);
            if (! $isEngp && $metadata) {
                $metadata['canonical_frequency'] = $definition->reporting_frequency;
                $defaultDeadline = $conservation->defaultDeadlineRule($key, $metadata['default_activity'] ?? null, ($metadata['activity_documents'][$metadata['default_activity'] ?? ''][0] ?? null));
                $metadata['canonical_deadline_mode'] = $defaultDeadline['deadline_mode'];
                $metadata['canonical_deadline_days'] = $defaultDeadline['deadline_days'];
            }
            DB::table('module_definitions')->where('id', $definition->id)->update([
                'requirement_domain' => $isEngp ? 'engp' : 'pa',
                'requirement_key' => $key,
                'requirement_metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('module_definitions', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('module_definitions'))->pluck('name')->all();
            if (in_array('module_definitions_requirement_domain_is_active_index', $indexes, true)) $table->dropIndex('module_definitions_requirement_domain_is_active_index');
            if (in_array('module_definitions_requirement_domain_requirement_key_index', $indexes, true)) $table->dropIndex('module_definitions_requirement_domain_requirement_key_index');
            $columns = array_values(array_filter(['requirement_domain', 'requirement_key', 'requirement_metadata', 'effective_from', 'effective_to', 'first_applicable_year'], fn (string $column): bool => Schema::hasColumn('module_definitions', $column)));
            if ($columns !== []) $table->dropColumn($columns);
        });
    }
};