<?php

use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function submissionFormOffice(string $name, string $code, string $type = 'cenro'): OrganizationalOffice
{
    return OrganizationalOffice::query()->firstOrCreate(['name' => $name], ['code' => $code, 'office_type' => $type, 'is_active' => true]);
}

function submissionFormArea(User $actor, OrganizationalOffice $office, string $name, string $shortName): ProtectedArea
{
    $area = ProtectedArea::query()->create(['name' => $name, 'short_name' => $shortName, 'category' => 'Protected Landscape', 'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    ProtectedAreaOfficeAssignment::query()->create(['protected_area_id' => $area->id, 'organizational_office_id' => $office->id, 'assignment_type' => 'supervising', 'assigned_by' => $actor->id]);
    return $area;
}

function submissionFormActor(string $role, string $office): User
{
    foreach (['aws.view', 'aws.create', 'aws.update', 'technical-reports.view'] as $ability) Permission::findOrCreate($ability, 'web');
    $spatieRole = Role::findOrCreate($role, 'web');
    $spatieRole->givePermissionTo(['aws.view', 'aws.create', 'aws.update', 'technical-reports.view']);
    $user = User::factory()->create(['section' => $role === 'Super Admin' ? 'CDS' : 'CENRO_CDS_FOCAL', 'unit_assignment' => 'conservation', 'office_designated' => $office, 'protected_area_id' => null]);
    $user->assignRole($spatieRole);
    return $user;
}

test('AWS submission options use canonical PA office assignments including Baganga PAs', function (): void {
    $bagangaOffice = submissionFormOffice('CENRO Baganga', 'cenro_baganga');
    $matiOffice = submissionFormOffice('CENRO Mati', 'cenro_mati');
    $setupActor = User::factory()->create();
    $bagangaPa = submissionFormArea($setupActor, $bagangaOffice, 'Baganga Scope Test PA', 'BAG-SCOPE');
    $matiPa = submissionFormArea($setupActor, $matiOffice, 'Mati Scope Test PA', 'MAT-SCOPE');
    $baganga = submissionFormActor('CENRO CDS Focal Person', 'CENRO Baganga');
    $scope = app(SubmissionFormScopeService::class);
    $options = $scope->options($baganga);

    expect(collect($options['targetOffices'])->pluck('name')->all())->toBe(['CENRO Baganga'])
        ->and(collect($options['protectedAreasByOffice'][(string) $bagangaOffice->id])->pluck('id')->all())->toContain($bagangaPa->id)
        ->and(collect($options['protectedAreasByOffice'][(string) $bagangaOffice->id])->pluck('id')->all())->not->toContain($matiPa->id);

    $this->actingAs($baganga)->get(route('aws.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('targetOffices.0.id', $bagangaOffice->id)
        ->where('targetOffices.0.name', 'CENRO Baganga')
        ->where('protectedAreasByOffice.'.$bagangaOffice->id.'.0.id', $bagangaPa->id));
});

test('global administrator receives office-specific canonical PA options and forged office PA pairs are rejected', function (): void {
    $bagangaOffice = submissionFormOffice('CENRO Baganga', 'cenro_baganga');
    $matiOffice = submissionFormOffice('CENRO Mati', 'cenro_mati');
    $setupActor = User::factory()->create();
    $bagangaPa = submissionFormArea($setupActor, $bagangaOffice, 'Baganga Scoped Test PA', 'BAG-SCOPE-2');
    $matiPa = submissionFormArea($setupActor, $matiOffice, 'Mati Scoped Test PA', 'MAT-SCOPE-2');
    $admin = submissionFormActor('Super Admin', 'PENRO Davao Oriental');
    $scope = app(SubmissionFormScopeService::class);
    $options = $scope->options($admin);

    expect(collect($options['targetOffices'])->pluck('name')->all())->toContain('CENRO Baganga', 'CENRO Mati')
        ->and(collect($options['protectedAreasByOffice'][(string) $bagangaOffice->id])->pluck('id')->all())->toContain($bagangaPa->id)
        ->and(collect($options['protectedAreasByOffice'][(string) $bagangaOffice->id])->pluck('id')->all())->not->toContain($matiPa->id)
        ->and(fn () => $scope->resolve($admin, $bagangaOffice->id, $matiPa->id))->toThrow(ValidationException::class)
        ->and(fn () => $scope->resolve($admin, 'Unlisted Free Text Office', $bagangaPa->id))->toThrow(ValidationException::class);

    $this->actingAs($admin)->get(route('aws.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('protectedAreasByOffice.'.$bagangaOffice->id.'.0.id', $bagangaPa->id)
        ->where('protectedAreasByOffice.'.$matiOffice->id.'.0.id', $matiPa->id));

    $cenro = submissionFormActor('CENRO CDS Focal Person', 'CENRO Baganga');
    expect(fn () => $scope->resolve($cenro, $matiOffice->id, $matiPa->id))->toThrow(HttpException::class);
    $this->actingAs($cenro)->get(route('conservation-reports.index', 'regular_pamb'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('protectedAreasByOffice.'.$bagangaOffice->id.'.0.id', $bagangaPa->id));
});

test('PENRO and legacy PAMO protected areas keep their canonical office scope', function (): void {
    $penroOffice = submissionFormOffice('PENRO Davao Oriental', 'penro_davao_oriental', 'penro');
    $setupActor = User::factory()->create();
    $mhrws = submissionFormArea($setupActor, $penroOffice, 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 'MHRWS-SCOPE');
    $pamo = User::factory()->create([
        'section' => 'PAMO',
        'unit_assignment' => 'conservation',
        'office_designated' => 'PENRO Davao Oriental',
        'protected_area_id' => $mhrws->id,
    ]);
    $pamo->assignRole(Role::findOrCreate('PAMO', 'web'));

    $options = app(SubmissionFormScopeService::class)->options($pamo);

    expect(collect($options['targetOffices'])->pluck('name')->all())->toBe(['PENRO Davao Oriental'])
        ->and(collect($options['protectedAreasByOffice'][(string) $penroOffice->id])->pluck('id')->all())->toBe([$mhrws->id]);
});

test('AWS submission rejects a forged target office and foreign office PA pair', function (): void {
    Storage::fake('local');
    $bagangaOffice = submissionFormOffice('CENRO Baganga', 'cenro_baganga');
    $matiOffice = submissionFormOffice('CENRO Mati', 'cenro_mati');
    $setupActor = User::factory()->create();
    $matiPa = submissionFormArea($setupActor, $matiOffice, 'Mati Forgery Test PA', 'MAT-FORGE');
    $baganga = submissionFormActor('CENRO CDS Focal Person', 'CENRO Baganga');

    $this->actingAs($baganga)->post(route('aws.store'), [
        'protected_area_id' => $matiPa->id,
        'target_office' => $bagangaOffice->id,
        'document_type' => 'Final Report',
        'monitoring_period_start' => '2026-01-01',
        'monitoring_period_end' => '2026-01-31',
        'report_file' => UploadedFile::fake()->create('synthetic.pdf', 10, 'application/pdf'),
    ])->assertForbidden();

    $this->assertDatabaseMissing('aws', ['protected_area_id' => $matiPa->id]);
});

test('active report forms share canonical office and protected area selectors', function (): void {
    foreach ([
        resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'),
        resource_path('js/Pages/ConservationReports/Index.jsx'),
        resource_path('js/Pages/Imea/ReportSubmissions.jsx'),
        resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'),
        resource_path('js/Pages/Engp/Index.jsx'),
        resource_path('js/Pages/Imea/MaintenanceReports.jsx'),
        resource_path('js/Pages/Ipaf/Index.jsx'),
        resource_path('js/Pages/ManagementPlans/Form.jsx'),
    ] as $path) {
        $source = file_get_contents($path);
        expect($source)->toContain('targetOffices');
    }
    expect(file_get_contents(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx')))->toContain('ProtectedAreaSelect')
        ->and(file_get_contents(resource_path('js/Pages/ManagementPlans/Form.jsx')))->toContain('ProtectedAreaSelect');

    $sharedTracker = file_get_contents(resource_path('js/Pages/Bms/ReportSubmissionTracker.jsx'));
    $awsWrapper = file_get_contents(resource_path('js/Pages/AWS/AwsReportSubmissionTracker.jsx'));
    $bmsController = file_get_contents(app_path('Http/Controllers/BmsReportSubmissionController.php'));
    $awsController = file_get_contents(app_path('Http/Controllers/AwsController.php'));
    expect($sharedTracker)->toContain('date_accomplished_required !== false')
        ->and($awsWrapper)->toContain('date_accomplished_required: false')
        ->and($bmsController)->toContain("'date_accomplished' => ['required', 'date']")
        ->and($awsController)->toContain("'date_accomplished' => ['nullable', 'date']");

    $sharedSelect = file_get_contents(resource_path('js/Components/Form/ScopedOptionSelect.jsx'));
    $datePicker = file_get_contents(resource_path('js/Components/DatePicker.jsx'));
    $dateRangePicker = file_get_contents(resource_path('js/Components/DateRangePicker.jsx'));
    expect($sharedSelect)->toContain('aria-required={required || undefined}')
        ->and($sharedSelect)->toContain('dark:placeholder:text-gray-400')
        ->and($datePicker)->toContain('dark:text-red-400')
        ->and($datePicker)->toContain('aria-required={required || undefined}')
        ->and($dateRangePicker)->toContain('dark:text-red-400')
        ->and($dateRangePicker)->toContain('aria-required={required || undefined}');

    $retired = file_get_contents(resource_path('js/Pages/TechnicalReports/Form.jsx'));
    expect($retired)->not->toContain('TargetOfficeSelect');
});
