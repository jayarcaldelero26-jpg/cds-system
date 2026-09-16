<?php

use App\Models\Aws;
use App\Models\AwsObservation;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Role;
use Illuminate\Http\UploadedFile;

function awsDomainUser(): User
{
    $user = User::factory()->create(['section' => 'CDS']);
    $user->assignRole(Role::findOrCreate('CDS Admin', 'web'));
    return $user;
}

function awsDomainArea(User $user): ProtectedArea
{
    return ProtectedArea::create([
        'name' => 'AWS Domain Test PA', 'short_name' => 'ADTPA',
        'category' => 'Protected Landscape', 'municipality' => 'Mati',
        'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);
}

test('aws report contract stores office and quarter period fields without observation metrics', function () {
    $user = awsDomainUser();
    $area = awsDomainArea($user);

    $this->actingAs($user)->post(route('aws.store'), [
        'protected_area_id' => $area->id,
        'target_office' => 'CENRO Mati',
        'station_name' => 'Station A', 'location' => 'Field site',
        'activity_name' => 'AWS monitoring', 'document_type' => 'Final Report',
        'reporting_year' => 2026, 'quarter' => 1,
        'monitoring_period_start' => '2026-01-01', 'monitoring_period_end' => '2026-01-15',
        'semester' => '1st Semester', 'report_period_type' => 'Quarterly',
        'date_conducted' => '2026-01-15', 'date_accomplished' => '2026-01-16',
        'status' => 'Approve',
        'report_file' => UploadedFile::fake()->create('aws-report.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $report = Aws::query()->where('protected_area_id', $area->id)->sole();
    expect($report->target_office)->toBe('CENRO Mati')
        ->and($report->reporting_year)->toBe(2026)
        ->and($report->quarter)->toBe(1)
        ->and($report->monitoring_period_start->toDateString())->toBe('2026-01-01')
        ->and($report->monitoring_period_end->toDateString())->toBe('2026-01-15')
        ->and($report->precipitation)->toBeNull();
});

test('aws observation rows are separate from report rows and excluded from submission tracking', function () {
    $user = awsDomainUser();
    $area = awsDomainArea($user);
    $observation = AwsObservation::create([
        'protected_area_id' => $area->id, 'station_name' => 'Station A', 'location' => 'Field',
        'report_period_type' => 'Daily', 'start_date' => '2026-02-01', 'end_date' => '2026-02-01',
        'timestamps' => 'February 1, 2026', 'status' => 'Active', 'air_temperature' => 25,
    ]);

    expect(Aws::query()->whereKey($observation->legacy_aws_id)->exists())->toBeFalse();

    $this->actingAs($user);
    $rows = app(SubmissionTrackingService::class)->records([], 100);
    expect(collect($rows)->where('source', 'aws')->where('source_id', $observation->id)->isEmpty())->toBeTrue();

    $this->actingAs($user)->get(route('submission-tracking.index'))->assertOk();
});
