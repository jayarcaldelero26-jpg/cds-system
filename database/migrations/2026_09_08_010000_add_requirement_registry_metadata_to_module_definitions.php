<?php

use AppDomain\Modules\ProgramArea;
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
        Schema::table('module_definitions', function (Blueprint $table): void {
            $table->string('requirement_domain')->nullable()->after('program_area');
            $table->string('requirement_key')->nullable()->after('code');
            $table->json('requirement_metadata')->nullable()->after('description');
            $table->date('effective_from')->nullable()->after('is_active');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->unsignedSmallInteger('first_applicable_year')->nullable()->after('effective_to');

            $table->index(['requirement_domain', 'is_active']);
            $table->index(['requirement_domain', 'requirement_key']);
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
            $table->dropIndex(['requirement_domain', 'is_active']);
            $table->dropIndex(['requirement_domain', 'requirement_key']);
            $table->dropColumn([
                'requirement_domain', 'requirement_key', 'requirement_metadata', 'effective_from',
                'effective_to', 'first_applicable_year',
            ]);
        });
    }
};
