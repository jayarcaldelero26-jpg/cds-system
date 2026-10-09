<?php

use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('tracking index reuses one actor PA scope per request without crossing office scope', function (): void {
    $matiOffice = OrganizationalOffice::query()->where('code', 'cenro_mati')->firstOrFail();
    $bagangaOffice = OrganizationalOffice::query()->where('code', 'cenro_baganga')->firstOrFail();
    $matiArea = ProtectedArea::create([
        'name' => 'Scope Reuse Mati Area', 'short_name' => 'SRMA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
    ]);
    $bagangaArea = ProtectedArea::create([
        'name' => 'Scope Reuse Baganga Area', 'short_name' => 'SRBA', 'category' => 'Protected Landscape',
        'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI',
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $matiArea->id, 'organizational_office_id' => $matiOffice->id,
        'assignment_type' => 'supervising',
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $bagangaArea->id, 'organizational_office_id' => $bagangaOffice->id,
        'assignment_type' => 'supervising',
    ]);

    $makeActor = static function (string $office): User {
        $actor = User::factory()->create([
            'is_active' => true, 'is_approved' => true, 'unit_assignment' => 'conservation',
            'section' => 'CENRO_CDS_CHIEF', 'office_designated' => $office,
        ]);
        $actor->assignRole(Role::findOrCreate('no_role', 'web'));
        $actor->givePermissionTo(Permission::findOrCreate('submission-tracking.view', 'web'));

        return $actor;
    };
    $matiActor = $makeActor('CENRO Mati');
    $bagangaActor = $makeActor('CENRO Baganga');

    $capture = false;
    $assignmentReads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$capture, &$assignmentReads): void {
        if ($capture && str_contains(strtolower($query->sql), 'protected_area_office_assignments')) {
            $assignmentReads++;
        }
    });

    $perform = function (User $actor) use (&$assignmentReads, &$capture): array {
        $this->actingAs($actor);
        $assignmentReads = 0;
        $capture = true;
        try {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'cds-system.test'])
                ->get('http://cds-system.test/submission-tracking?view=incoming')
                ->assertOk();
        } finally {
            $capture = false;
        }

        return [$assignmentReads, $response->inertiaProps()['filterOptions']['protectedAreas']];
    };

    [$matiReads, $matiAreas] = $perform($matiActor);
    [$bagangaReads, $bagangaAreas] = $perform($bagangaActor);

    expect($matiReads)->toBe(3)
        ->and(collect($matiAreas)->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([$matiArea->id])
        ->and($bagangaReads)->toBe(3)
        ->and(collect($bagangaAreas)->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([$bagangaArea->id]);
});

