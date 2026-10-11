<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\ProtectedArea;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organizational_offices') && Schema::hasTable('protected_area_office_assignments') && Schema::hasTable('protected_areas')) {
            $routingPolicy = app(ProtectedAreaRoutingPolicy::class);
            $areas = DB::table('protected_areas')->where('status', 'Active')->whereNotNull('pamo')->where('pamo', '<>', '');
            if (Schema::hasColumn('protected_areas', 'deleted_at')) $areas->whereNull('deleted_at');
            $areas->orderBy('id')->get(['id', 'name', 'short_name', 'pamo'])->each(function (object $area) use ($routingPolicy): void {
                $name = trim((string) $area->pamo);
                $identity = self::normalizeOfficeName($name);
                if ($identity === '' || mb_strlen($name) > 150) return;

                // An existing explicit assignment is authoritative. Never replace it or
                // create an unused office based on the legacy display-name field.
                if (DB::table('protected_area_office_assignments')->where('protected_area_id', $area->id)->where('assignment_type', 'pamo')->exists()) return;

                $areaModel = new ProtectedArea();
                $areaModel->setRawAttributes(['id' => $area->id, 'name' => $area->name, 'short_name' => $area->short_name], true);
                $expectedSupervisorType = $routingPolicy->isDirectProtectedArea($areaModel) ? 'penro' : 'cenro';
                $supervisors = DB::table('protected_area_office_assignments as assignment')
                    ->join('organizational_offices as office', 'office.id', '=', 'assignment.organizational_office_id')
                    ->where('assignment.protected_area_id', $area->id)
                    ->where('assignment.assignment_type', 'supervising')
                    ->get(['office.id', 'office.office_type', 'office.is_active']);
                if ($supervisors->count() !== 1
                    || $supervisors[0]->office_type !== $expectedSupervisorType
                    || ! (bool) $supervisors[0]->is_active) return;

                // A legacy office label can seed an identity only if it has one
                // unambiguous active PAMO office match, or no prior office match.
                $namedOffices = DB::table('organizational_offices')->get(['id', 'name', 'office_type', 'is_active'])
                    ->filter(fn (object $office): bool => self::normalizeOfficeName((string) $office->name) === $identity)
                    ->values();
                if ($namedOffices->count() > 1) return;
                if ($namedOffices->count() === 1) {
                    $namedOffice = $namedOffices->first();
                    if ($namedOffice->office_type !== 'pamo' || ! (bool) $namedOffice->is_active) return;
                    $officeId = (int) $namedOffice->id;
                } else {
                    // Keep codes distinct when different labels collapse to the same slug.
                    $slug = Str::slug($identity) ?: 'office';
                    $code = 'pamo_'.substr($slug, 0, 40).'_'.substr(hash('sha256', $identity), 0, 32);
                    $office = DB::table('organizational_offices')->where('code', $code)->first();
                    if ($office) {
                        if ($office->office_type !== 'pamo' || ! (bool) $office->is_active || self::normalizeOfficeName((string) $office->name) !== $identity) return;
                        $officeId = (int) $office->id;
                    } else {
                        $officeId = DB::table('organizational_offices')->insertGetId([
                            'code' => $code,
                            'name' => $name,
                            'office_type' => 'pamo',
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('protected_area_office_assignments')->insert([
                    'protected_area_id' => $area->id,
                    'organizational_office_id' => $officeId,
                    'assignment_type' => 'pamo',
                    'assigned_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        if (Schema::hasTable('submission_routing_snapshots')) {
            Schema::table('submission_routing_snapshots', function (Blueprint $table): void {
                $table->string('origin_kind', 24)->nullable()->after('graph_version');
                $table->foreignId('protected_area_id')->nullable()->after('origin_kind')->constrained('protected_areas')->restrictOnDelete();
                $table->foreignId('pamo_office_id')->nullable()->after('protected_area_id')->constrained('organizational_offices')->restrictOnDelete();
                $table->string('pamo_office_name', 160)->nullable()->after('pamo_office_id');
                $table->foreignId('responsible_cenro_office_id')->nullable()->after('pamo_office_name')->constrained('organizational_offices')->restrictOnDelete();
                $table->string('responsible_cenro_name', 160)->nullable()->after('responsible_cenro_office_id');
                $table->foreignId('originator_user_id')->nullable()->after('responsible_cenro_name')->constrained('users')->nullOnDelete();
                $table->dateTime('origin_started_at')->nullable()->after('originator_user_id');
            });
        }

        if (Schema::hasTable('office_tat_configuration_versions') && ! Schema::hasColumn('office_tat_configuration_versions', 'organizational_office_id')) {
            Schema::table('office_tat_configuration_versions', function (Blueprint $table): void {
                $table->foreignId('organizational_office_id')->nullable()->after('office');
                $table->foreign('organizational_office_id', 'office_tat_org_office_fk')
                    ->references('id')
                    ->on('organizational_offices')
                    ->restrictOnDelete();
                $table->index(['office_category', 'organizational_office_id', 'period_key'], 'office_tat_org_office_lookup_idx');
            });
        }

        if (! Schema::hasTable('pamo_pamb_drafts')) {
            Schema::create('pamo_pamb_drafts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('draft_uuid')->unique();
                $table->string('start_idempotency_key', 64)->nullable()->unique();
                $table->foreignId('protected_area_id')->constrained('protected_areas')->restrictOnDelete();
                $table->foreignId('pamo_office_id')->constrained('organizational_offices')->restrictOnDelete();
                $table->string('pamo_office_name', 160);
                $table->foreignId('originator_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->string('workflow_key', 80);
                $table->json('report_payload')->nullable();
                $table->string('mov_path', 500)->nullable();
                $table->string('mov_file_name', 255)->nullable();
                $table->dateTime('started_at');
                $table->string('status', 20)->default('draft');
                $table->foreignId('conservation_report_submission_id')->nullable()->unique()->constrained('conservation_report_submissions')->restrictOnDelete();
                $table->dateTime('forwarded_at')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->timestamps();
                $table->index(['pamo_office_id', 'protected_area_id', 'status', 'started_at'], 'pamo_pamb_draft_scope_idx');
                $table->index(['workflow_key', 'started_at'], 'pamo_pamb_draft_workflow_idx');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('PAMO draft provenance and captured origin identity are retained for compatibility-aware rollback; use a forward fix.');
    }

    private static function normalizeOfficeName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
};
