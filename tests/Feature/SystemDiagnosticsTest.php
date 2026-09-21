<?php

use App\Models\User;
use App\Services\Diagnostics\SystemDiagnosticsService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function diagnosticsAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS', 'is_active' => true, 'is_approved' => true]);
    $user->assignRole(Role::firstOrCreate(['name' => 'CDS Admin', 'guard_name' => 'web']));
    $user->givePermissionTo(Permission::findOrCreate('system-diagnostics.view', 'web'));
    return $user;
}

test('authorized admin can open diagnostics while unauthorized users receive 403', function (): void {
    $admin = diagnosticsAdmin();
    $this->actingAs($admin)->get(route('settings.system-diagnostics.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/SystemDiagnostics')
        ->has('diagnostics.groups')
        ->has('diagnostics.summary'));

    $user = User::factory()->create(['is_active' => true, 'is_approved' => true]);
    $this->actingAs($user)->get(route('settings.system-diagnostics.index'))->assertForbidden();
});

test('diagnostics return independent normalized read-only checks and sanitized snapshot', function (): void {
    config()->set('app.debug', true);
    $result = app(SystemDiagnosticsService::class)->run();
    expect($result['overall'])->toBeIn(['healthy', 'warning', 'critical'])
        ->and($result['checks'])->not->toBeEmpty()
        ->and(collect($result['checks'])->every(fn (array $check): bool => in_array($check['status'], ['pass', 'warning', 'fail', 'not_verified'], true) && $check['read_only'] === true))->toBeTrue()
        ->and(json_encode($result['snapshot']))->not->toContain('APP_KEY')
        ->and(json_encode($result['snapshot']))->not->toContain('password')
        ->and(collect($result['checks'])->firstWhere('check', 'application.debug')['status'])->toBe('warning');
});

test('opening diagnostics does not mutate domain or operational event tables', function (): void {
    $before = collect(['users', 'document_routing_events', 'pamb_routing_events', 'audit_logs', 'notifications', 'failed_jobs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::getSchemaBuilder()->hasTable($table) ? DB::table($table)->count() : null]);
    app(SystemDiagnosticsService::class)->run();
    $after = $before->mapWithKeys(fn ($count, $table): array => [$table => $count === null || ! DB::getSchemaBuilder()->hasTable($table) ? null : DB::table($table)->count()]);
    expect($after->all())->toBe($before->all());
});

test('diagnostics presentation uses human-readable labels, one debug card, and collapsed technical details', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Admin/Settings/SystemDiagnostics.jsx'));
    expect($source)->toContain('Application Environment')
        ->and($source)->toContain('Database Connection')
        ->and($source)->toContain('View Technical Details')
        ->and($source)->toContain('All verified system checks are operating normally.')
        ->and(substr_count($source, "check.check === 'application.debug'"))->toBe(1);
});
