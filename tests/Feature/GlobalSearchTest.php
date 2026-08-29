<?php

use App\Models\ConservationReportSubmission;
use App\Models\ProtectedArea;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function searchUser(array $abilities = []): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    foreach ($abilities as $ability) {
        $user->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }
    return $user;
}

function globalSearch(User $user, string $query): array
{
    return test()->actingAs($user)->getJson(route('api.global-search', ['q' => $query]))->assertOk()->json();
}

function searchGroup(array $payload, string $key): array
{
    return collect($payload['groups'])->firstWhere('key', $key)['results'] ?? [];
}

test('an authenticated user can search modules they are authorized to access', function () {
    $user = searchUser(['bms.view']);
    $results = searchGroup(globalSearch($user, 'BMS'), 'navigation');

    expect($results)->toContain(['type' => 'navigation', 'title' => 'BMS', 'subtitle' => 'Biodiversity Monitoring System', 'url' => '/bms', 'icon' => 'leaf', 'badge' => 'Navigation']);
});

test('unauthorized modules are excluded by the server', function () {
    $user = searchUser(['bms.view']);
    $payload = globalSearch($user, 'BAMS');

    expect($payload['total'])->toBe(0)
        ->and(json_encode($payload))->not->toContain('BAMS');
});

test('ENGP weekly search resolves to the current workflow route', function () {
    $user = searchUser(['technical-reports.view']);
    $results = searchGroup(globalSearch($user, 'ENGP Weekly Accomplishment'), 'navigation');

    expect($results)->toContain([
        'type' => 'navigation',
        'title' => 'ENGP Weekly Accomplishment',
        'subtitle' => 'ENGP weekly report workflow',
        'url' => route('engp-reports.index', ['workflow' => 'weekly_accomplishment']),
        'icon' => 'document',
        'badge' => 'Navigation',
    ]);
});

test('protected area search returns authorized protected area results', function () {
    $user = searchUser(['protected-areas.view']);
    ProtectedArea::create(['name' => 'Aliwagwag Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Cateel', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);

    $results = searchGroup(globalSearch($user, 'Aliwagwag'), 'protected_areas');

    expect($results[0]['title'])->toBe('Aliwagwag Protected Landscape')
        ->and($results[0]['url'])->toContain('/protected-areas?search=Aliwagwag')
        ->and($results[0]['badge'])->toBe('Protected Area');
});

test('report search returns only matching records available through an authorized workflow', function () {
    $user = searchUser(['technical-reports.view']);
    $area = ProtectedArea::create(['name' => 'Pujada Bay Protected Landscape', 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);
    ConservationReportSubmission::create(['workflow_key' => 'regular_pamb', 'protected_area_id' => $area->id, 'target_office' => 'PENRO Mati', 'activity_name' => 'Regular PAMB Meetings', 'document_type' => 'Minutes', 'reporting_period' => 'Quarter 1', 'date_accomplished' => '2026-01-10', 'created_by' => $user->id, 'updated_by' => $user->id]);

    $results = searchGroup(globalSearch($user, 'Regular PAMB'), 'reports');

    expect($results[0]['title'])->toBe('Regular PAMB Meetings')
        ->and($results[0]['url'])->toEndWith('/conservation-reports/regular_pamb')
        ->and($results[0]['badge'])->toBe('Report');
});

test('user search requires the existing user management authorization', function () {
    $viewer = searchUser();
    $admin = User::factory()->create(['name' => 'Searchable Administrator', 'section' => 'CDS']);
    Role::findOrCreate('CDS Admin', 'web');
    $admin->assignRole('CDS Admin');

    expect(searchGroup(globalSearch($viewer, 'Searchable'), 'users'))->toBe([])
        ->and(searchGroup(globalSearch($admin, 'Searchable'), 'users')[0])->toMatchArray(['title' => 'Searchable Administrator', 'badge' => 'User']);
});

test('short queries return an empty lightweight payload', function () {
    $payload = globalSearch(searchUser(['bms.view']), 'B');

    expect($payload)->toBe(['groups' => [], 'total' => 0]);
});

test('search results are capped at five per category and omit sensitive fields', function () {
    $user = searchUser(['protected-areas.view']);
    foreach (range(1, 6) as $number) {
        ProtectedArea::create(['name' => "Search Reserve {$number}", 'category' => 'Protected Landscape', 'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI', 'status' => 'Active', 'created_by' => $user->id, 'updated_by' => $user->id]);
    }

    $payload = globalSearch($user, 'Search Reserve');
    $serialized = json_encode($payload);

    expect(searchGroup($payload, 'protected_areas'))->toHaveCount(5)
        ->and($serialized)->not->toContain('source_id')
        ->and($serialized)->not->toContain('mov_url')
        ->and($serialized)->not->toContain('mov_file_path')
        ->and($serialized)->not->toContain('password')
        ->and($serialized)->not->toContain('remember_token');
});
