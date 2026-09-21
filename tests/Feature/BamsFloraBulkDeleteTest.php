<?php

use App\Models\BamsFlora;
use App\Models\ProtectedArea;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function bamsBulkDeleteAdmin(): User
{
    Role::findOrCreate('CDS Admin', 'web');
    Permission::findOrCreate('bams.delete', 'web');

    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole('CDS Admin');
    $user->givePermissionTo('bams.delete');

    return $user;
}

function bamsBulkDeleteArea(User $user, string $name = 'BAMS Bulk Delete Area'): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name,
        'category' => 'Protected Landscape',
        'municipality' => 'Mati',
        'province' => 'Davao Oriental',
        'region' => 'Region XI',
        'status' => 'Active',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

function bamsBulkDeleteFlora(ProtectedArea $area, string $species): BamsFlora
{
    return BamsFlora::create([
        'protected_area_id' => $area->id,
        'quadrat_no' => 1,
        'species_code' => $species,
        'dbh' => 1.5,
    ]);
}

test('BAMS bulk delete rejects an empty selection without deleting records', function (): void {
    $user = bamsBulkDeleteAdmin();
    $record = bamsBulkDeleteFlora(bamsBulkDeleteArea($user), 'KEEP-EMPTY');

    $this->actingAs($user)
        ->post(route('bams.bulk-destroy'), ['ids' => []])
        ->assertSessionHasErrors('ids');

    expect(BamsFlora::query()->whereKey($record->id)->exists())->toBeTrue();
});

test('BAMS bulk delete removes only the selected authorized flora records', function (): void {
    $user = bamsBulkDeleteAdmin();
    $area = bamsBulkDeleteArea($user);
    $first = bamsBulkDeleteFlora($area, 'DELETE-ONE');
    $second = bamsBulkDeleteFlora($area, 'DELETE-TWO');
    $kept = bamsBulkDeleteFlora($area, 'KEEP-THREE');

    $this->actingAs($user)
        ->post(route('bams.bulk-destroy'), ['ids' => [$first->id, $second->id]])
        ->assertRedirect();

    expect(BamsFlora::query()->whereKey([$first->id, $second->id])->count())->toBe(0)
        ->and(BamsFlora::query()->whereKey($kept->id)->exists())->toBeTrue();
});

test('BAMS bulk delete remains protected-area scoped', function (): void {
    $owner = bamsBulkDeleteAdmin();
    $record = bamsBulkDeleteFlora(bamsBulkDeleteArea($owner, 'Restricted BAMS Area'), 'RESTRICTED');
    $outsider = User::factory()->create(['section' => 'PAMO']);
    Permission::findOrCreate('bams.delete', 'web');
    $outsider->givePermissionTo('bams.delete');

    $this->actingAs($outsider)
        ->post(route('bams.bulk-destroy'), ['ids' => [$record->id]])
        ->assertForbidden();

    expect(BamsFlora::query()->whereKey($record->id)->exists())->toBeTrue();
});