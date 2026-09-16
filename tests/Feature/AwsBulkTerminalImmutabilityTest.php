<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function awsBulkAdmin(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    return $user;
}

function awsBulkReport(User $user, bool $completed = false): Aws
{
    $area = ProtectedArea::create([
        'name' => 'AWS Bulk PA '.uniqid(), 'short_name' => 'ABP', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'XI',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    return Aws::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati', 'station_name' => 'AWS Station',
        'location' => 'Mati', 'activity_name' => 'AWS Report', 'document_type' => 'Report',
        'status' => 'Active', 'date_accomplished' => '2026-08-01',
        'date_report_released_cenro' => $completed ? '2026-08-02' : null,
        'date_received_penro' => $completed ? '2026-08-03' : null,
        'date_endorsed_regional' => $completed ? '2026-08-04' : null,
    ]);
}

test('AWS bulk delete removes an all-mutable selection', function (): void {
    $user = awsBulkAdmin();
    $first = awsBulkReport($user);
    $second = awsBulkReport($user);

    $this->actingAs($user)->post(route('aws.bulk-destroy'), ['ids' => [$first->id, $second->id]])->assertRedirect(route('aws.index'));

    expect(Aws::query()->whereKey([$first->id, $second->id])->count())->toBe(0);
});

test('AWS bulk delete rejects the complete selection when one report is terminal', function (): void {
    Storage::fake('local');
    $user = awsBulkAdmin();
    $mutable = awsBulkReport($user);
    $completed = awsBulkReport($user, completed: true);
    $completed->update(['report_file_path' => 'aws/terminal-report.pdf', 'report_file_name' => 'terminal-report.pdf']);
    Storage::disk('local')->put('aws/terminal-report.pdf', 'terminal attachment');

    $this->actingAs($user)
        ->post(route('aws.bulk-destroy'), ['ids' => [$mutable->id, $completed->id]])
        ->assertSessionHasErrors('submission');

    expect(Aws::query()->whereKey($mutable->id)->exists())->toBeTrue()
        ->and(Aws::query()->whereKey($completed->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('aws/terminal-report.pdf'))->toBeTrue();
});
