<?php

use App\Models\User;
use App\Services\Archive\GoogleDriveDocumentArchiveGateway;
use App\Services\Storage\GoogleDriveStorageCapacityProvider;
use App\Services\Storage\ServerStorageCapacityProvider;
use App\Services\Storage\StorageCapacityFormatter;
use App\Services\Storage\StorageCapacityService;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function settingsSuperAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS', 'is_active' => true, 'is_approved' => true]);
    $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
    return $user;
}

test('Settings uses a premium navigation shell and preserves current settings routes', function (): void {
    $admin = settingsSuperAdmin();
    $this->actingAs($admin)->get(route('settings.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/Index')
        ->where('auth.canViewStorage', true)
        ->has('auth.canViewSystemDiagnostics'));

    $source = file_get_contents(resource_path('js/Components/Admin/SettingsShell.jsx'));
    $index = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.jsx'));
    expect($source)->toContain('Module Management')->toContain('Compliance Alerts')->toContain('System Diagnostics')->toContain('aria-current')
        ->and($index)->toContain('SettingsShell');
});

test('CDS Admin receives no Storage navigation authorization and cannot open Storage settings', function (): void {
    config()->set('services.document_archive.driver', 'fake');
    $admin = User::factory()->create(['section' => 'CDS', 'is_active' => true, 'is_approved' => true]);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    $this->actingAs($admin)->get(route('settings.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/Index')
        ->where('auth.canViewStorage', false)
        ->where('auth.user.account_role', 'CDS Admin')
        ->where('auth.user.roles', ['CDS Admin'])
        ->where('auth.unitVisibility.isGlobal', true)
        ->where('auth.unitVisibility.effectiveUnits', ['conservation', 'development']));
    $this->actingAs($admin)->get(route('settings.storage.index'))->assertForbidden();
});

test('Super Admin keeps its canonical shared identity and exact Storage capability', function (): void {
    config()->set('services.document_archive.driver', 'fake');
    $super = settingsSuperAdmin();

    $this->actingAs($super)->get(route('settings.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('auth.user.account_role', 'Super Admin')
        ->where('auth.user.roles', ['Super Admin'])
        ->where('auth.canViewStorage', true)
        ->where('auth.unitVisibility.isGlobal', true));
});

test('Settings navigation uses shared authorization data for Storage and active state', function (): void {
    $shell = file_get_contents(resource_path('js/Components/Admin/SettingsShell.jsx'));
    $index = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.jsx'));
    $storage = file_get_contents(resource_path('js/Pages/Admin/Settings/Storage.jsx'));

    expect($index)->toContain('props.auth?.canViewStorage')
        ->and($storage)->toContain('props.auth?.canViewStorage')
        ->and($shell)->toContain('aria-current')->toContain("active === item.title");
});

test('Settings child pages share the accessible Settings header and keep their navigation item active', function (): void {
    config()->set('services.document_archive.driver', 'fake');
    $super = settingsSuperAdmin();
    $super->givePermissionTo(
        Permission::findOrCreate('module-definitions.view', 'web'),
        Permission::findOrCreate('compliance-alerts.manage', 'web'),
        Permission::findOrCreate('system-diagnostics.view', 'web'),
    );

    $this->actingAs($super)->get(route('module-definitions.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Settings/ModuleManagement'));
    $this->get(route('settings.compliance-alerts'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('ComplianceAlerts/Index')->where('view', 'settings'));
    $this->get(route('settings.storage.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Settings/Storage'));
    $this->get(route('settings.system-diagnostics.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Settings/SystemDiagnostics'));

    $header = file_get_contents(resource_path('js/Components/Admin/SettingsPageHeader.jsx'));
    $shell = file_get_contents(resource_path('js/Components/Admin/SettingsShell.jsx'));
    expect($header)->toContain('href="/settings"')
        ->toContain('aria-label={`Back to Settings from ${title}`}')
        ->toContain('focus-visible:ring-2')
        ->toContain('dark:text-gray-300')
        ->toContain('break-words')
        ->and($shell)->toContain('aria-current={selected ? \'page\' : undefined}');

    foreach ([
        [resource_path('js/Pages/Admin/Settings/ModuleManagement.jsx'), 'active="Module Management"', 'Manage CDS-SMART modules and availability.'],
        [resource_path('js/Pages/ComplianceAlerts/Index.jsx'), "active: 'Compliance Alerts'", 'Configure compliance monitoring and alert behavior.'],
        [resource_path('js/Pages/Admin/Settings/Storage.jsx'), 'active="Storage"', 'Monitor application and cloud storage capacity.'],
        [resource_path('js/Pages/Admin/Settings/SystemDiagnostics.jsx'), 'active="System Diagnostics"', 'Review system health and diagnostic information.'],
    ] as [$path, $active, $description]) {
        $source = file_get_contents($path);
        expect($source)->toContain('SettingsPageHeader')
            ->toContain($active)
            ->toContain($description);
    }
});

test('only exact Super Admin can open Storage settings', function (): void {
    config()->set('services.document_archive.driver', 'fake');
    $super = settingsSuperAdmin();
    $this->actingAs($super)->get(route('settings.storage.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Admin/Settings/Storage')->has('capacity.providers'));

    $admin = User::factory()->create(['section' => 'CDS', 'is_active' => true, 'is_approved' => true]);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->actingAs($admin)->get(route('settings.storage.index'))->assertForbidden();
});

test('server provider returns dynamic normalized filesystem capacity without claiming hosting quota', function (): void {
    $result = app(ServerStorageCapacityProvider::class)->measure();
    expect($result)->toHaveKeys(['provider', 'status', 'used_bytes', 'total_bytes', 'free_bytes', 'used_percentage', 'measured_at', 'source', 'message'])
        ->and($result['provider'])->toBe('server')
        ->and($result['source'])->toBe('local_filesystem')
        ->and($result['message'])->toContain('hosting-plan quota');
});

test('Google Drive provider normalizes quota and handles missing finite limits', function (): void {
    $gateway = Mockery::mock(GoogleDriveDocumentArchiveGateway::class);
    $gateway->shouldReceive('storageQuota')->once()->andReturn(['limit' => '1000', 'usage' => '250']);
    $result = (new GoogleDriveStorageCapacityProvider($gateway))->measure();
    expect($result['status'])->toBe('available')->and($result['used_bytes'])->toBe(250)->and($result['free_bytes'])->toBe(750)->and($result['used_percentage'])->toBe(25);

    $unlimited = Mockery::mock(GoogleDriveDocumentArchiveGateway::class);
    $unlimited->shouldReceive('storageQuota')->once()->andReturn(['usage' => '250']);
    $result = (new GoogleDriveStorageCapacityProvider($unlimited))->measure();
    expect($result['status'])->toBe('available')
        ->and($result['used_bytes'])->toBe(250)
        ->and($result['total_bytes'])->toBeNull()
        ->and($result['free_bytes'])->toBeNull()
        ->and($result['used_percentage'])->toBeNull()
        ->and($result['message'])->toContain('without a storage limit');
});

test('Google Drive capacity fails closed for missing usage and an invalid or zero reported limit', function (): void {
    foreach ([
        ['limit' => '1000'],
        ['limit' => '1000', 'usage' => 'not-a-number'],
        ['limit' => '0', 'usage' => '250'],
        ['limit' => null, 'usage' => '250'],
    ] as $quota) {
        $gateway = Mockery::mock(GoogleDriveDocumentArchiveGateway::class);
        $gateway->shouldReceive('storageQuota')->once()->andReturn($quota);
        $result = (new GoogleDriveStorageCapacityProvider($gateway))->measure();
        expect($result['status'])->toBe('unavailable')
            ->and($result['used_bytes'])->toBeNull()
            ->and($result['total_bytes'])->toBeNull()
            ->and($result['free_bytes'])->toBeNull();
    }
});

test('Google Drive capacity hides provider failures and reports no fabricated values', function (): void {
    $gateway = Mockery::mock(GoogleDriveDocumentArchiveGateway::class);
    $gateway->shouldReceive('storageQuota')->once()->andThrow(new \App\Services\Archive\GoogleDriveArchiveException('private provider details', 400));

    $result = (new GoogleDriveStorageCapacityProvider($gateway))->measure();

    expect($result['status'])->toBe('unavailable')
        ->and($result['used_bytes'])->toBeNull()
        ->and($result['total_bytes'])->toBeNull()
        ->and($result['free_bytes'])->toBeNull()
        ->and($result['message'])->toBe('Google Drive capacity could not be retrieved safely.')
        ->and($result['message'])->not->toContain('private provider details');
});

test('capacity formatter supports MB GB and TB without raw byte presentation', function (): void {
    expect(StorageCapacityFormatter::bytes(846 * 1024 ** 2))->toBe('846 MB')
        ->and(StorageCapacityFormatter::bytes((int) (4.2 * 1024 ** 3)))->toBe('4.2 GB')
        ->and(StorageCapacityFormatter::bytes((int) (1.4 * 1024 ** 4)))->toBe('1.4 TB')
        ->and(StorageCapacityFormatter::bytes(null))->toBeNull();
});

test('capacity cache prevents repeated provider reads and refresh invalidates only capacity cache', function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    $server = Mockery::mock(ServerStorageCapacityProvider::class);
    $server->shouldReceive('measure')->twice()->andReturn(
        ['provider' => 'server', 'status' => 'available', 'used_bytes' => 10, 'total_bytes' => 100, 'free_bytes' => 90, 'used_percentage' => 10, 'measured_at' => now()->toIso8601String(), 'source' => 'fake', 'message' => null],
        ['provider' => 'server', 'status' => 'available', 'used_bytes' => 11, 'total_bytes' => 100, 'free_bytes' => 89, 'used_percentage' => 11, 'measured_at' => now()->toIso8601String(), 'source' => 'fake', 'message' => null],
    );
    $drive = Mockery::mock(\App\Services\Storage\GoogleDriveStorageCapacityProvider::class);
    $drive->shouldReceive('measure')->twice()->andReturn(
        ['provider' => 'google_drive', 'status' => 'available', 'used_bytes' => 20, 'total_bytes' => 100, 'free_bytes' => 80, 'used_percentage' => 20, 'measured_at' => now()->toIso8601String(), 'source' => 'fake', 'message' => null],
        ['provider' => 'google_drive', 'status' => 'available', 'used_bytes' => 21, 'total_bytes' => 100, 'free_bytes' => 79, 'used_percentage' => 21, 'measured_at' => now()->toIso8601String(), 'source' => 'fake', 'message' => null],
    );
    $service = new StorageCapacityService($server, $drive);
    expect(data_get($service->current(), 'providers.server.used_bytes'))->toBe(10)
        ->and(data_get($service->current(), 'providers.server.used_bytes'))->toBe(10)
        ->and(data_get($service->refresh(), 'providers.server.used_bytes'))->toBe(11)
        ->and(data_get($service->current(), 'providers.google_drive.used_bytes'))->toBe(21)
        ->and(Cache::has('settings.storage_capacity.v1'))->toBeTrue();
});

test('only an authorized Super Admin can refresh Storage capacity', function (): void {
    $admin = User::factory()->create(['section' => 'CDS', 'is_active' => true, 'is_approved' => true]);
    $admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $server = Mockery::mock(ServerStorageCapacityProvider::class);
    $server->shouldNotReceive('measure');
    $drive = Mockery::mock(\App\Services\Storage\GoogleDriveStorageCapacityProvider::class);
    $drive->shouldNotReceive('measure');
    app()->instance(StorageCapacityService::class, new StorageCapacityService($server, $drive));

    $this->actingAs($admin)->post(route('settings.storage.refresh'))->assertForbidden();
});

test('Storage page uses semantic warning and critical thresholds without hard-coded capacity values', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Admin/Settings/Storage.jsx'));
    expect($source)->toContain('>= 80')->toContain('>= 90')->toContain('capacity.providers')
        ->toContain('CDS-SMART Final Reports')
        ->toContain('Capacity quota is reported separately')
        ->and($source)->not->toContain('15 GB')->not->toContain('50 GB')->not->toContain('100 GB');
});
