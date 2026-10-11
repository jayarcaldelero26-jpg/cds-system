<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PamoPambOriginMigrationTest extends TestCase
{
    public function test_backfill_is_rehearsed_only_on_valid_isolated_sqlite_fixtures(): void
    {
        $priorDefault = DB::getDefaultConnection();
        self::assertSame('sqlite', DB::connection()->getDriverName(), 'Refusing to rehearse on a non-SQLite default connection.');
        self::assertSame(':memory:', (string) config('database.connections.'.$priorDefault.'.database'), 'Refusing to rehearse unless the default SQLite database is in memory.');

        $connection = 'pamo_origin_migration_rehearsal';
        config(['database.connections.'.$connection => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            self::assertSame('sqlite', DB::connection()->getDriverName());
            self::assertSame(':memory:', DB::connection()->getDatabaseName());
            $this->createPreMigrationSchema();
            $db = DB::connection($connection);
            $now = now();

            $cenroId = $db->table('organizational_offices')->insertGetId([
                'code' => 'cenro_baganga', 'name' => 'CENRO Baganga', 'office_type' => 'cenro', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $penroId = $db->table('organizational_offices')->insertGetId([
                'code' => 'penro_davao_oriental', 'name' => 'PENRO Davao Oriental', 'office_type' => 'penro', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $inactiveCenroId = $db->table('organizational_offices')->insertGetId([
                'code' => 'cenro_inactive_fixture', 'name' => 'CENRO Inactive Fixture', 'office_type' => 'cenro', 'is_active' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $addArea = function (string $name, string $shortName, string $pamoName, ?int $supervisorId) use ($db, $now): int {
                $areaId = $db->table('protected_areas')->insertGetId([
                    'name' => $name, 'short_name' => $shortName, 'status' => 'Active', 'pamo' => $pamoName,
                ]);
                if ($supervisorId !== null) {
                    $db->table('protected_area_office_assignments')->insert([
                        'protected_area_id' => $areaId, 'organizational_office_id' => $supervisorId,
                        'assignment_type' => 'supervising', 'assigned_by' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                return $areaId;
            };

            $aplId = $addArea('Aliwagwag Protected Landscape', 'APL', 'PAMO Shared Legacy Identity', $cenroId);
            $aplSecondId = $addArea('Second Protected Landscape', 'SPL', 'PAMO Shared Legacy Identity', $cenroId);
            $mhrwsId = $addArea('Mt. Hamiguitan Range Wildlife Sanctuary', 'MHRWS', 'PAMO Direct Identity', $penroId);
            $missingSupervisorId = $addArea('Missing Supervisor Fixture', 'MSF', 'PAMO Missing Supervisor', null);

            $wrongSupervisorOfficeId = $db->table('organizational_offices')->insertGetId([
                'code' => 'penro_wrong_supervisor_fixture', 'name' => 'PENRO Wrong Supervisor', 'office_type' => 'penro', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $wrongSupervisorId = $addArea('Wrong Supervisor Fixture', 'WSF', 'PAMO Wrong Supervisor', $wrongSupervisorOfficeId);
            $inactiveSupervisorId = $addArea('Inactive Supervisor Fixture', 'ISF', 'PAMO Inactive Supervisor', $inactiveCenroId);

            $explicitPamoId = $db->table('organizational_offices')->insertGetId([
                'code' => 'pamo_explicit_authoritative', 'name' => 'Authoritative PAMO Identity', 'office_type' => 'pamo', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $explicitAreaId = $addArea('Explicit Mapping Fixture', 'EMF', 'Different Legacy Display Name', $cenroId);
            $db->table('protected_area_office_assignments')->insert([
                'protected_area_id' => $explicitAreaId, 'organizational_office_id' => $explicitPamoId,
                'assignment_type' => 'pamo', 'assigned_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $ambiguousOne = $db->table('organizational_offices')->insertGetId([
                'code' => 'pamo_ambiguous_one', 'name' => 'PAMO Ambiguous Fixture', 'office_type' => 'pamo', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $ambiguousTwo = $db->table('organizational_offices')->insertGetId([
                'code' => 'pamo_ambiguous_two', 'name' => ' pamo   ambiguous fixture ', 'office_type' => 'pamo', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            self::assertNotSame($ambiguousOne, $ambiguousTwo);
            $ambiguousAreaId = $addArea('Ambiguous Office Fixture', 'AOF', 'PAMO Ambiguous Fixture', $cenroId);

            $slugOneId = $addArea('Slug Collision One', 'SC1', 'PAMO Lake/Site', $cenroId);
            $slugTwoId = $addArea('Slug Collision Two', 'SC2', 'PAMO Lake Site', $cenroId);
            $collisionName = 'PAMO Code Collision Fixture';
            $normalizedCollisionName = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($collisionName)));
            $collisionCode = 'pamo_'.substr(Str::slug($normalizedCollisionName) ?: 'office', 0, 40).'_'.substr(hash('sha256', $normalizedCollisionName), 0, 32);
            $db->table('organizational_offices')->insert([
                'code' => $collisionCode, 'name' => 'Unrelated CENRO Code Collision', 'office_type' => 'cenro', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $codeCollisionAreaId = $addArea('Code Collision Fixture', 'CCF', $collisionName, $cenroId);

            $inactiveAreaId = $db->table('protected_areas')->insertGetId([
                'name' => 'Inactive PA Fixture', 'short_name' => 'IPF', 'status' => 'Inactive', 'pamo' => 'PAMO Inactive PA',
            ]);
            $db->table('protected_area_office_assignments')->insert([
                'protected_area_id' => $inactiveAreaId, 'organizational_office_id' => $cenroId,
                'assignment_type' => 'supervising', 'assigned_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $deletedAreaId = $addArea('Soft Deleted PA Fixture', 'SDPF', 'PAMO Soft Deleted PA', $cenroId);
            $db->table('protected_areas')->where('id', $deletedAreaId)->update(['deleted_at' => $now]);

            $db->table('roles')->insert(['id' => 1, 'name' => 'existing-role']);
            $db->table('permissions')->insert(['id' => 1, 'name' => 'existing-permission']);
            $db->table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'fixture', 'model_id' => 1]);
            $sourceId = $db->table('conservation_report_submissions')->insertGetId([
                'workflow_key' => 'regular_pamb', 'target_office' => 'CENRO Baganga', 'remarks' => 'Legacy source fixture',
            ]);
            $db->table('submission_routing_snapshots')->insert([
                'source_key' => 'conservation', 'source_id' => $sourceId, 'graph_version' => 'legacy-graph',
            ]);
            $db->table('document_routing_events')->insert([
                'source_type' => 'conservation', 'source_id' => $sourceId, 'event_key' => 'received', 'metadata' => '{"fixture":true}',
            ]);
            $db->table('office_tat_configuration_versions')->insert([
                'office_category' => 'CENRO', 'office' => 'CENRO Baganga', 'period_key' => '2026-H2',
            ]);

            $officeCountBefore = $db->table('organizational_offices')->count();
            $sourceBefore = (array) $db->table('conservation_report_submissions')->where('id', $sourceId)->first();
            $snapshotBefore = (array) $db->table('submission_routing_snapshots')->where('source_id', $sourceId)->first();
            $eventBefore = (array) $db->table('document_routing_events')->where('source_id', $sourceId)->first();
            $tatBefore = (array) $db->table('office_tat_configuration_versions')->first();
            $permissionCountsBefore = [
                $db->table('roles')->count(), $db->table('permissions')->count(), $db->table('model_has_roles')->count(),
            ];

            $migration = require base_path('database/migrations/2026_10_11_000001_add_pamo_pamb_origin_drafts_and_office_identity.php');
            $migration->up();

            $assignment = fn (int $areaId) => $db->table('protected_area_office_assignments')
                ->where('protected_area_id', $areaId)->where('assignment_type', 'pamo')->first();
            $aplAssignment = $assignment($aplId);
            $aplSecondAssignment = $assignment($aplSecondId);
            $mhrwsAssignment = $assignment($mhrwsId);
            self::assertNotNull($aplAssignment);
            self::assertNotNull($aplSecondAssignment);
            self::assertNotNull($mhrwsAssignment);
            self::assertSame((int) $aplAssignment->organizational_office_id, (int) $aplSecondAssignment->organizational_office_id);
            self::assertSame('pamo', $db->table('organizational_offices')->where('id', $aplAssignment->organizational_office_id)->value('office_type'));
            self::assertSame('pamo', $db->table('organizational_offices')->where('id', $mhrwsAssignment->organizational_office_id)->value('office_type'));
            self::assertSame('penro', $db->table('organizational_offices')->join('protected_area_office_assignments as supervisor', 'supervisor.organizational_office_id', '=', 'organizational_offices.id')->where('supervisor.protected_area_id', $mhrwsId)->where('supervisor.assignment_type', 'supervising')->value('office_type'));

            self::assertNull($assignment($missingSupervisorId));
            self::assertNull($assignment($wrongSupervisorId));
            self::assertNull($assignment($inactiveSupervisorId));
            self::assertNull($assignment($ambiguousAreaId));
            self::assertNull($assignment($codeCollisionAreaId));
            self::assertSame($explicitPamoId, (int) $assignment($explicitAreaId)->organizational_office_id);
            self::assertNull($assignment($inactiveAreaId));
            self::assertNull($assignment($deletedAreaId));

            $slugOneOffice = $db->table('organizational_offices')->where('id', $assignment($slugOneId)?->organizational_office_id)->first();
            $slugTwoOffice = $db->table('organizational_offices')->where('id', $assignment($slugTwoId)?->organizational_office_id)->first();
            self::assertNotNull($slugOneOffice);
            self::assertNotNull($slugTwoOffice);
            self::assertNotSame($slugOneOffice->code, $slugTwoOffice->code);
            self::assertSame($officeCountBefore + 4, $db->table('organizational_offices')->count());

            self::assertSame($sourceBefore, (array) $db->table('conservation_report_submissions')->where('id', $sourceId)->first());
            self::assertSame($snapshotBefore['graph_version'], $db->table('submission_routing_snapshots')->where('source_id', $sourceId)->value('graph_version'));
            self::assertSame($snapshotBefore['source_id'], $db->table('submission_routing_snapshots')->where('source_id', $sourceId)->value('source_id'));
            self::assertSame($eventBefore, (array) $db->table('document_routing_events')->where('source_id', $sourceId)->first());
            self::assertSame($tatBefore['office'], $db->table('office_tat_configuration_versions')->value('office'));
            self::assertNull($db->table('office_tat_configuration_versions')->value('organizational_office_id'));
            self::assertSame($permissionCountsBefore, [
                $db->table('roles')->count(), $db->table('permissions')->count(), $db->table('model_has_roles')->count(),
            ]);
            foreach ([
                'origin_kind', 'protected_area_id', 'pamo_office_id', 'pamo_office_name',
                'responsible_cenro_office_id', 'responsible_cenro_name', 'originator_user_id', 'origin_started_at',
            ] as $column) self::assertTrue(Schema::hasColumn('submission_routing_snapshots', $column));
            foreach ([
                'draft_uuid', 'start_idempotency_key', 'protected_area_id', 'pamo_office_id', 'pamo_office_name',
                'originator_user_id', 'created_by', 'updated_by', 'workflow_key', 'report_payload', 'mov_path',
                'started_at', 'status', 'conservation_report_submission_id', 'forwarded_at', 'lock_version',
            ] as $column) self::assertTrue(Schema::hasColumn('pamo_pamb_drafts', $column));
        } finally {
            DB::setDefaultConnection($priorDefault);
            DB::purge($connection);
        }
    }

    private function createPreMigrationSchema(): void
    {
        Schema::create('protected_areas', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('short_name')->nullable(); $table->string('status'); $table->string('pamo')->nullable(); $table->softDeletes();
        });
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('conservation_report_submissions', function (Blueprint $table): void {
            $table->id(); $table->string('workflow_key'); $table->string('target_office')->nullable(); $table->text('remarks')->nullable();
        });
        Schema::create('organizational_offices', function (Blueprint $table): void {
            $table->id(); $table->string('code', 80)->unique(); $table->string('name', 150); $table->string('office_type', 30); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('protected_area_office_assignments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('protected_area_id')->constrained('protected_areas'); $table->foreignId('organizational_office_id')->constrained('organizational_offices'); $table->string('assignment_type'); $table->unsignedBigInteger('assigned_by')->nullable(); $table->timestamps(); $table->unique(['protected_area_id', 'assignment_type']);
        });
        Schema::create('submission_routing_snapshots', function (Blueprint $table): void {
            $table->id(); $table->string('source_key'); $table->unsignedBigInteger('source_id'); $table->string('graph_version');
        });
        Schema::create('office_tat_configuration_versions', function (Blueprint $table): void {
            $table->id(); $table->string('office_category'); $table->string('office'); $table->string('period_key');
        });
        Schema::create('document_routing_events', function (Blueprint $table): void {
            $table->id(); $table->string('source_type'); $table->unsignedBigInteger('source_id'); $table->string('event_key'); $table->text('metadata');
        });
        Schema::create('roles', function (Blueprint $table): void { $table->id(); $table->string('name'); });
        Schema::create('permissions', function (Blueprint $table): void { $table->id(); $table->string('name'); });
        Schema::create('model_has_roles', function (Blueprint $table): void { $table->unsignedBigInteger('role_id'); $table->string('model_type'); $table->unsignedBigInteger('model_id'); });
    }
}
