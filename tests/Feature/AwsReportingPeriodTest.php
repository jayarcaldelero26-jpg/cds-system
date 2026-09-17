<?php

use App\Models\Aws;
use App\Models\ProtectedArea;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

function awsPeriodUser(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));

    return $user;
}

function awsPeriodArea(User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'AWS Period Test PA', 'short_name' => 'AWSPT',
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
}

test('AWS coverage ranges derive the year and preserve the selected quarter', function (): void {
    Storage::fake('local');
    $user = awsPeriodUser();
    $area = awsPeriodArea($user);
    $ranges = [
        [1, '2026-01-01', '2026-01-15'],
        [1, '2026-01-16', '2026-01-31'],
        [1, '2026-02-16', '2026-02-28'],
        [1, '2028-02-16', '2028-02-29'],
        [2, '2026-04-16', '2026-04-30'],
        [3, '2026-07-01', '2026-07-31'],
        [4, '2026-10-01', '2026-10-31'],
    ];

    foreach ($ranges as [$quarter, $start, $end]) {
        $this->actingAs($user)->post(route('aws.store'), [
            'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
            'activity_name' => 'Monitoring and Maintenance of AWS',
            'quarter' => $quarter, 'document_type' => 'Final Report',
            'monitoring_period_start' => $start, 'monitoring_period_end' => $end,
            'date_conducted' => $start, 'date_accomplished' => $end,
            'report_file' => UploadedFile::fake()->create('aws-report.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
    }

    expect(Aws::query()->count())->toBe(count($ranges))
        ->and(Aws::query()->where('reporting_year', 2028)->value('quarter'))->toBe(1)
        ->and(Aws::query()->where('document_type', 'Final Report')->count())->toBe(count($ranges));
});

test('AWS rejects a reporting quarter that does not match or crosses the coverage range', function (): void {
    $user = awsPeriodUser();
    $area = awsPeriodArea($user);
    $payload = [
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => 'Monitoring and Maintenance of AWS', 'document_type' => 'Progress Report',
        'quarter' => 1, 'monitoring_period_start' => '2026-03-25', 'monitoring_period_end' => '2026-04-05',
        'date_conducted' => '2026-03-20', 'date_accomplished' => '2026-04-06',
        'report_file' => UploadedFile::fake()->create('aws-report.pdf', 10, 'application/pdf'),
    ];

    $this->actingAs($user)->post(route('aws.store'), $payload)->assertStatus(422);
    expect(Aws::query()->count())->toBe(0);
});
