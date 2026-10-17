<?php

namespace Tests\Feature;

use App\Models\ConservationReportSubmission;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SharedAccessProjectionRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\ModuleDefinitionSeeder::class);
    }

    public function test_real_shared_projection_distinguishes_pamo_cenro_penro_and_global_admin(): void
    {
        $pamo = $this->assignedPamo('share-pamo', 'Shared Access PAMO');
        $pamoProps = $this->dashboardProps($pamo['user']);

        $this->assertTrue(data_get($pamoProps, 'auth.canViewPamoReports'));
        $this->assertTrue(data_get($pamoProps, 'auth.canViewPambWorkflow'));
        $this->assertFalse(data_get($pamoProps, 'auth.canBrowseConservationModules'));
        $this->assertSame(
            $pamo['area']->id,
            data_get($pamoProps, 'auth.user.protected_area_id'),
        );
        $pamoLinks = collect($pamoProps['genericModuleNavigation'])->pluck('href')->all();
        $pamoNativeLinks = collect($pamoProps['pamoNativeModuleNavigation'])->pluck('href')->all();
        $this->assertContains('/conservation-reports/regular_pamb', $pamoLinks);
        $this->assertNotContains('/bms', $pamoLinks);
        $this->assertContains('/pamo-native-reports/bms', $pamoNativeLinks);

        $cenro = $this->organizationalUser('CENRO_CDS_CHIEF', 'CENRO Baganga', 'CENRO CDS Chief');
        $cenroProps = $this->dashboardProps($cenro);
        $this->assertTrue(data_get($cenroProps, 'auth.canBrowseConservationModules'));
        $this->assertTrue(data_get($cenroProps, 'auth.canViewTechnicalReports'));
        $this->assertTrue(data_get($cenroProps, 'auth.canViewPambWorkflow'));
        $this->assertFalse(data_get($cenroProps, 'auth.canViewPamoReports'));
        $this->assertContains('/conservation-reports/maintenance_monuments', collect($cenroProps['genericModuleNavigation'])->pluck('href'));
        $this->assertSame([], $cenroProps['pamoNativeModuleNavigation']);

        $penro = $this->organizationalUser('PENRO_CDS_CHIEF', 'PENRO Davao Oriental', 'PENRO CDS Chief');
        $penroProps = $this->dashboardProps($penro);
        $this->assertTrue(data_get($penroProps, 'auth.canBrowseConservationModules'));
        $this->assertTrue(data_get($penroProps, 'auth.canViewPambWorkflow'));
        $this->assertFalse(data_get($penroProps, 'auth.canViewPamoReports'));
        $this->assertContains('/conservation-reports/maintenance_monuments', collect($penroProps['genericModuleNavigation'])->pluck('href'));
        $this->assertSame([], $penroProps['pamoNativeModuleNavigation']);

        $admin = User::factory()->create(['section' => 'CDS', 'unit_assignment' => null]);
        $admin->assignRole(Role::findByName('CDS Admin', 'web'));
        $adminProps = $this->dashboardProps($admin);
        $this->assertTrue(data_get($adminProps, 'auth.canManageUsers'));
        $this->assertTrue(data_get($adminProps, 'auth.canBrowseConservationModules'));
        $this->assertTrue(data_get($adminProps, 'auth.canViewPambWorkflow'));
        $this->assertFalse(data_get($adminProps, 'auth.canViewPamoReports'));
        $this->assertContains('/conservation-reports/maintenance_monuments', collect($adminProps['genericModuleNavigation'])->pluck('href'));
        $this->assertSame([], $adminProps['pamoNativeModuleNavigation']);
    }

    public function test_shared_projection_stays_actor_scoped_across_pamo_a_b_a_requests(): void
    {
        $actorA = $this->assignedPamo('scope-a', 'Scope A PAMO');
        $actorB = $this->assignedPamo('scope-b', 'Scope B PAMO');
        $this->createPambReport($actorA['user'], $actorA['area']);

        $firstA = $this->dashboardProps($actorA['user']);
        $firstProjection = $this->sharedAccessProjection($firstA);
        $this->assertSame($actorA['area']->id, data_get($firstA, 'auth.user.protected_area_id'));
        $this->assertTrue(data_get($firstA, 'auth.canViewPamoReports'));

        $propsB = $this->dashboardProps($actorB['user']);
        $this->assertSame($actorB['area']->id, data_get($propsB, 'auth.user.protected_area_id'));
        $this->assertTrue(data_get($propsB, 'auth.canViewPamoReports'));
        $this->assertSame($firstProjection, $this->sharedAccessProjection($propsB));

        $this->actingAs($actorB['user'])
            ->get(route('conservation-reports.index', [
                'workflow' => 'regular_pamb',
                'protected_area_id' => $actorA['area']->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('submissions.data', 0));

        $lastA = $this->dashboardProps($actorA['user']);
        $this->assertSame($firstProjection, $this->sharedAccessProjection($lastA));
        $this->assertSame($actorA['area']->id, data_get($lastA, 'auth.user.protected_area_id'));
        $this->assertSame($actorA['user']->id, data_get($lastA, 'auth.user.id'));
    }

    public function test_missing_or_incompatible_pamo_assignment_denies_shared_access_and_navigation(): void
    {
        $valid = $this->assignedPamo('mismatch-source', 'Mapped PAMO');
        $mismatchOffice = OrganizationalOffice::query()->create([
            'code' => 'shared_access_unmapped_pamo',
            'name' => 'Unmapped PAMO Office',
            'office_type' => 'pamo',
            'is_active' => true,
        ]);
        $missingArea = User::factory()->create([
            'section' => 'PAMO',
            'unit_assignment' => null,
            'office_designated' => $valid['office']->name,
            'protected_area_id' => null,
        ]);
        $missingArea->assignRole(Role::findByName('PAMO', 'web'));
        $wrongOffice = User::factory()->create([
            'section' => 'PAMO',
            'unit_assignment' => null,
            'office_designated' => $mismatchOffice->name,
            'protected_area_id' => $valid['area']->id,
        ]);
        $wrongOffice->assignRole(Role::findByName('PAMO', 'web'));

        foreach ([$missingArea, $wrongOffice] as $actor) {
            $props = $this->dashboardProps($actor);
            $this->assertFalse(data_get($props, 'auth.canViewPamoReports'));
            $this->assertFalse(data_get($props, 'auth.canViewPambWorkflow'));
            $this->assertFalse(data_get($props, 'auth.canBrowseConservationModules'));
            $this->assertFalse(data_get($props, 'auth.unitVisibility.conservation'));
            $this->assertSame([], $props['genericModuleNavigation']);
            $this->assertSame([], $props['pamoNativeModuleNavigation']);
        }
    }

    public function test_missing_required_access_and_routing_only_cenro_do_not_gain_module_navigation(): void
    {
        $unprivileged = User::factory()->create(['section' => 'Viewer', 'unit_assignment' => null]);
        $unprivileged->assignRole(Role::findByName('no_role', 'web'));
        $deniedProps = $this->dashboardProps($unprivileged);
        $this->assertFalse(data_get($deniedProps, 'auth.canBrowseConservationModules'));
        $this->assertFalse(data_get($deniedProps, 'auth.canViewTechnicalReports'));
        $this->assertFalse(data_get($deniedProps, 'auth.canViewPambWorkflow'));
        $this->assertFalse(data_get($deniedProps, 'auth.canViewPamoReports'));
        $this->assertSame([], $deniedProps['genericModuleNavigation']);
        $this->assertSame([], $deniedProps['pamoNativeModuleNavigation']);
        $this->actingAs($unprivileged)->get('/conservation-reports/regular_pamb')->assertForbidden();

        $records = $this->organizationalUser('CENRO_RECORDS', 'CENRO Baganga', 'CENRO Records Unit');
        $records->givePermissionTo('technical-reports.view');
        $recordsProps = $this->dashboardProps($records);
        $this->assertFalse(data_get($recordsProps, 'auth.canBrowseConservationModules'));
        $this->assertFalse(data_get($recordsProps, 'auth.canViewTechnicalReports'));
        $this->assertFalse(data_get($recordsProps, 'auth.canViewPambWorkflow'));
        $this->assertSame([], $recordsProps['genericModuleNavigation']);
        $this->actingAs($records)->get('/conservation-reports/regular_pamb')->assertForbidden();
    }

    public function test_inactive_and_unapproved_actors_are_denied_by_inertia_middleware(): void
    {
        foreach ([
            [['is_approved' => false], 'pending_approval'],
            [['is_active' => false], 'account_inactive'],
        ] as [$state, $flashKey]) {
            $actor = User::factory()->create(array_merge([
                'section' => 'CDS',
                'unit_assignment' => 'conservation',
            ], $state));

            $this->actingAs($actor)
                ->get('/dashboard')
                ->assertRedirect(route('login'))
                ->assertSessionHas($flashKey, true);
            $this->assertGuest();
        }
    }

    /** @return array<string, mixed> */
    private function dashboardProps(User $actor): array
    {
        return $this->actingAs($actor)->get(route('dashboard'))->assertOk()->inertiaProps();
    }

    /** @return array<string, mixed> */
    private function sharedAccessProjection(array $props): array
    {
        return [
            'canViewPamoReports' => data_get($props, 'auth.canViewPamoReports'),
            'canViewPambWorkflow' => data_get($props, 'auth.canViewPambWorkflow'),
            'canBrowseConservationModules' => data_get($props, 'auth.canBrowseConservationModules'),
            'conservationVisible' => data_get($props, 'auth.unitVisibility.conservation'),
            'genericModuleNavigation' => $props['genericModuleNavigation'],
            'pamoNativeModuleNavigation' => $props['pamoNativeModuleNavigation'],
        ];
    }

    private function organizationalUser(string $category, string $office, string $roleName): User
    {
        $user = User::factory()->create([
            'section' => $category,
            'unit_assignment' => null,
            'office_designated' => $office,
            'protected_area_id' => null,
        ]);
        $user->assignRole(Role::findByName($roleName, 'web'));

        return $user;
    }

    /** @return array{user: User, area: ProtectedArea, office: OrganizationalOffice} */
    private function assignedPamo(string $code, string $label): array
    {
        $office = OrganizationalOffice::query()->create([
            'code' => $code.'_office',
            'name' => $label.' Office',
            'office_type' => 'pamo',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'section' => 'PAMO',
            'unit_assignment' => null,
            'office_designated' => $office->name,
            'protected_area_id' => null,
        ]);
        $user->assignRole(Role::findByName('PAMO', 'web'));

        $area = ProtectedArea::query()->create([
            'name' => $label.' Protected Landscape',
            'short_name' => $label,
            'category' => 'Protected Landscape',
            'municipality' => 'Baganga',
            'province' => 'Davao Oriental',
            'region' => 'Region XI',
            'status' => 'Active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $cenro = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
        ProtectedAreaOfficeAssignment::query()->create([
            'protected_area_id' => $area->id,
            'organizational_office_id' => $office->id,
            'assignment_type' => 'pamo',
            'assigned_by' => $user->id,
        ]);
        ProtectedAreaOfficeAssignment::query()->create([
            'protected_area_id' => $area->id,
            'organizational_office_id' => $cenro->id,
            'assignment_type' => 'supervising',
            'assigned_by' => $user->id,
        ]);
        $user->update(['protected_area_id' => $area->id]);

        return ['user' => $user->fresh(), 'area' => $area, 'office' => $office];
    }

    private function createPambReport(User $actor, ProtectedArea $area): ConservationReportSubmission
    {
        return ConservationReportSubmission::query()->create([
            'workflow_key' => 'regular_pamb',
            'protected_area_id' => $area->id,
            'target_office' => 'CENRO Baganga',
            'activity_name' => 'Regular PAMB',
            'document_type' => 'Minutes',
            'reporting_period' => 'Quarter 1',
            'date_conducted' => '2026-09-01',
            'date_accomplished' => '2026-09-01',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
