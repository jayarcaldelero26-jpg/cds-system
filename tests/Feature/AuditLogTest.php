<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['section' => 'CDS']);
    $this->admin->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    $this->admin->givePermissionTo(Permission::findOrCreate('audit-logs.view', 'web'));
    $this->unauthorized = User::factory()->create(['section' => 'CDS']);
});

function auditLogPayload(array $overrides = []): array
{
    return array_merge([
        'event_type' => 'testing',
        'action' => 'Audit Test Event',
        'entity_type' => User::class,
        'entity_id' => '1',
        'module' => 'Audit Tests',
        'summary' => 'A test audit event.',
        'metadata' => ['visible' => 'safe'],
        'user_id' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Phase 1 test agent',
    ], $overrides);
}

test('authorized CDS Admin can open the paginated slim Audit Logs list', function () {
    AuditLog::query()->create(auditLogPayload());
    foreach (array_fill(0, 25, auditLogPayload(['action' => 'Additional Event'])) as $payload) {
        AuditLog::query()->create($payload);
    }

    $response = $this->actingAs($this->admin)->get(route('audit-logs.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/AuditLogs/Index')
        ->where('logs.per_page', 25)
        ->where('logs.total', 26)
        ->missing('logs.data.0.metadata')
        ->missing('logs.data.0.ip_address')
        ->missing('logs.data.0.user_agent')
        ->where('logs.data.0.action', 'Additional Event'));
});

test('authorized Admin can fetch one Audit Log detail with sensitive metadata redacted', function () {
    $log = AuditLog::query()->create(auditLogPayload([
        'metadata' => [
            'visible' => 'safe',
            'password' => 'do-not-return',
            'APP_KEY' => 'do-not-return',
            'db_password' => 'do-not-return',
            'token' => 'do-not-return',
            'session' => 'do-not-return',
            'nested' => ['credential' => 'do-not-return', 'kept' => 'safe'],
        ],
    ]));

    $response = $this->actingAs($this->admin)->getJson(route('audit-logs.show', $log));

    $response->assertOk()
        ->assertJsonPath('id', $log->id)
        ->assertJsonPath('metadata.visible', 'safe')
        ->assertJsonPath('metadata.nested.kept', 'safe')
        ->assertJsonPath('ip_address', '127.0.0.1')
        ->assertJsonPath('user_agent', 'Phase 1 test agent');

    $metadata = $response->json('metadata');
    expect($metadata)->not->toHaveKeys(['password', 'APP_KEY', 'db_password', 'token', 'session'])
        ->and($metadata['nested'])->not->toHaveKey('credential');
});

test('unauthorized users cannot fetch Audit Log details and no edit/delete routes exist', function () {
    $log = AuditLog::query()->create(auditLogPayload());

    $this->actingAs($this->unauthorized)
        ->getJson(route('audit-logs.show', $log))
        ->assertForbidden();

    expect(Route::getRoutes()->getByName('audit-logs.store'))->toBeNull()
        ->and(Route::getRoutes()->getByName('audit-logs.update'))->toBeNull()
        ->and(Route::getRoutes()->getByName('audit-logs.destroy'))->toBeNull();
});
