<?php

use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;

function identityArea(User $owner, string $name, ?string $shortName): ProtectedArea
{
    return ProtectedArea::create([
        'name' => $name, 'short_name' => $shortName, 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

test('PA fallback uses exact normalized identity without BPL and PBPLS collision', function (): void {
    $owner = User::factory()->create();
    $resolver = app(OrganizationalAccessService::class);
    $bpl = identityArea($owner, 'BPL', ' BPL ');
    $pbpls = identityArea($owner, 'Pujada Bay Protected Landscape and Seascape (PBPLS)', ' PBPLS ');

    expect($resolver->supervisingOfficeNameForProtectedArea($bpl->id))->toBe('CENRO Baganga')
        ->and($resolver->supervisingOfficeNameForProtectedArea($pbpls->id))->toBe('CENRO Mati');

    $matcher = new ReflectionMethod($resolver, 'protectedAreaIdentityMatches');
    $matcher->setAccessible(true);
    expect($matcher->invoke($resolver, $pbpls, ['BPL'], []))->toBeFalse()
        ->and($matcher->invoke($resolver, $bpl, ['BPL'], []))->toBeTrue();
});

test('PA fallback preserves exact acronym and full-name routing map', function (): void {
    $owner = User::factory()->create();
    $resolver = app(OrganizationalAccessService::class);

    $cases = [
        ['Aliwagwag Protected Landscape', 'APL', 'CENRO Baganga'],
        ['BPL', 'BPL', 'CENRO Baganga'],
        ['BMSFR', 'BMSFR', 'CENRO Baganga'],
        ['Mati Protected Landscape', 'MPL', 'CENRO Mati'],
        ['Pujada Bay Protected Landscape and Seascape', 'PBPLS', 'CENRO Mati'],
        ['Mt. Hamiguitan Range Wildlife Sanctuary', 'MHRWS', 'PENRO Davao Oriental'],
    ];

    foreach ($cases as [$name, $shortName, $office]) {
        $area = identityArea($owner, $name, $shortName);
        expect($resolver->supervisingOfficeNameForProtectedArea($area->id))->toBe($office);
    }
});
