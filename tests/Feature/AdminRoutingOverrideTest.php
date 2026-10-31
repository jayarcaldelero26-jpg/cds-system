<?php

use App\Models\ConservationReportSubmission;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\AdminRoutingOverrideService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Spatie\Permission\Models\Permission;

function overrideAdmin(): User
{
    $user = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'CDS']);
    $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $user->givePermissionTo(Permission::findOrCreate('submission-tracking.admin-override', 'web'));
    return $user;
}

function overrideReport(User $owner): ConservationReportSubmission
{
    return ConservationReportSubmission::create([
        'workflow_key' => 'homestay', 'activity_name' => 'Override Homestay', 'target_office' => 'CENRO Mati',
        'date_accomplished' => '2026-08-03', 'created_by' => $owner->id, 'updated_by' => $owner->id,
    ]);
}

test('normal users cannot access emergency override and Super Admin without a passkey cannot start it', function (): void {
    $owner = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $report = overrideReport($owner);
    $admin = overrideAdmin();

    $this->actingAs($owner)->getJson(route('submission-tracking.admin-override.options', ['conservation', $report->id]))->assertForbidden();
    $this->actingAs($admin)->getJson(route('submission-tracking.admin-override.options', ['conservation', $report->id]))->assertStatus(422)->assertJsonPath('message', 'Register a passkey in Security settings before using Administrative Override.');
});

test('global role without the named override ability is denied options and service access', function (): void {
    $owner = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $report = overrideReport($owner);
    $adminWithoutAbility = User::factory()->create(['unit_assignment' => 'conservation', 'section' => 'CDS']);
    $adminWithoutAbility->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    $this->actingAs($adminWithoutAbility)
        ->getJson(route('submission-tracking.admin-override.options', ['conservation', $report->id]))
        ->assertForbidden();
    $this->postJson(route('submission-tracking.admin-override.execute', ['conservation', $report->id]), [
        'action' => 'forward_to_cenro_chief',
        'reason' => 'A role alone must not execute an override.',
        'credential' => [],
    ])->assertForbidden();

    expect(app(AdminRoutingOverrideService::class)->canUse($adminWithoutAbility))->toBeFalse()
        ->and($report->fresh()->routingEvents()->count())->toBe(0)
        ->and(\App\Models\SubmissionRoutingOverride::query()->count())->toBe(0);
});

test('Super Admin override choices come only from the current accountable generic stage', function (): void {
    $owner = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $chief = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Mati']);
    $report = overrideReport($owner);
    $tracking = app(SubmissionTrackingService::class);
    test()->actingAs($owner);
    $tracking->transition('conservation', $report->id, 'forward_to_cenro_chief', null, $owner->id);
    $tracking->transition('conservation', $report->id, 'receive_at_cenro_chief', null, $chief->id);

    $available = app(AdminRoutingOverrideService::class)->available('conservation', $report->id, overrideAdmin());
    expect($available['engine'])->toBe('generic')
        ->and(collect($available['actions'])->pluck('key')->all())->toContain('return_to_cenro_focal', 'forward_to_cenro_records')
        ->and(collect($available['actions'])->pluck('key')->all())->not->toContain('release_to_regional');
});


test('Super Admin receives override authority in the tracking page independently of passkey enrollment', function (): void {
    $admin = overrideAdmin();

    $this->actingAs($admin)
        ->get(route('submission-tracking.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.canAdminRoutingOverride', true));
});

test('normal users do not receive override authority in the tracking page', function (): void {
    $user = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_FOCAL]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.canAdminRoutingOverride', false));
});


test('Super Admin transit override exposes only PENRO Records Receive for a generic Homestay route', function (): void {
    $owner = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_RECORDS]);
    $report = overrideReport($owner);

    \App\Models\DocumentRoutingEvent::query()->create([
        'source_type' => 'conservation',
        'source_id' => $report->id,
        'workflow_key' => 'homestay',
        'event_key' => 'forwarded',
        'from_stage' => \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::CENRO_RECORDS,
        'to_stage' => \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS,
        'from_office' => 'CENRO Records Unit',
        'to_office' => 'PENRO Records Unit',
        'occurred_at' => now(),
        'recorded_by' => $owner->id,
    ]);

    $available = app(AdminRoutingOverrideService::class)->available('conservation', $report->id, overrideAdmin());

    expect($available['current_stage'])->toBe(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS)
        ->and($available['actions'][0]['accountable_category_key'])->toBe(OrganizationalAccessService::PENRO_RECORDS)
        ->and(collect($available['actions'])->pluck('key')->all())->toBe(['receive_at_penro_records']);
});

test('a stale passkey stage cannot replay a repeated action in a later correction cycle', function (): void {
    $owner = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_FOCAL, 'office_designated' => 'CENRO Mati']);
    $chief = User::factory()->create(['unit_assignment' => 'conservation', 'section' => OrganizationalAccessService::CENRO_CHIEF, 'office_designated' => 'CENRO Mati']);
    $admin = overrideAdmin();
    $report = overrideReport($owner);
    $overrides = app(AdminRoutingOverrideService::class);
    $initial = $overrides->available('conservation', $report->id, $admin);
    $tracking = app(SubmissionTrackingService::class);

    expect(collect($initial['actions'])->pluck('key')->all())->toContain('forward_to_cenro_chief');

    test()->actingAs($owner);
    $tracking->transition('conservation', $report->id, 'forward_to_cenro_chief', null, $owner->id);
    test()->actingAs($chief);
    $tracking->transition('conservation', $report->id, 'receive_at_cenro_chief', null, $chief->id);
    $tracking->transition('conservation', $report->id, 'return_to_cenro_focal', null, $chief->id, 'Correct the report before review.');
    test()->actingAs($owner);
    $tracking->transition('conservation', $report->id, 'receive_correction', null, $owner->id);

    $current = $overrides->available('conservation', $report->id, $admin);
    expect($current['current_stage'])->toBe($initial['current_stage'])
        ->and($current['state_token'])->not->toBe($initial['state_token'])
        ->and(collect($current['actions'])->pluck('key')->all())->toContain('forward_to_cenro_chief');

    $eventCount = \App\Models\DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count();
    $verifier = \Mockery::mock(\Laravel\Passkeys\Actions\VerifyPasskey::class);
    $verifier->shouldNotReceive('__invoke');
    app()->instance(\Laravel\Passkeys\Actions\VerifyPasskey::class, $verifier);
    $credential = (new ReflectionClass(\Webauthn\PublicKeyCredential::class))->newInstanceWithoutConstructor();
    $options = (new ReflectionClass(\Webauthn\PublicKeyCredentialRequestOptions::class))->newInstanceWithoutConstructor();

    expect(fn () => $overrides->execute(
        'conservation', $report->id, 'forward_to_cenro_chief', $admin, 'Stale stage verification', $credential, $options, $initial['current_stage'], $initial['state_token'],
    ))->toThrow(\Illuminate\Validation\ValidationException::class, 'The record changed while passkey verification was in progress.')
        ->and(\App\Models\DocumentRoutingEvent::query()->where('source_type', 'conservation')->where('source_id', $report->id)->count())->toBe($eventCount)
        ->and(\App\Models\SubmissionRoutingOverride::query()->where('source', 'conservation')->where('source_record_id', $report->id)->count())->toBe(0);
});
