<?php

use App\Models\BmsReportSubmission;
use App\Models\MobileApiToken;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->password = 'fictional-mobile-password';
    $this->focal = User::factory()->create([
        'name' => 'Fictional CENRO Focal',
        'email' => 'mobile-tracking@example.test',
        'password' => $this->password,
        'section' => OrganizationalAccessService::CENRO_FOCAL,
        'unit_assignment' => OrganizationalAccessService::CONSERVATION,
        'office_designated' => 'CENRO Mati',
    ]);
    $this->focal->givePermissionTo(Permission::findOrCreate('reports.view', 'web'));
    $this->focal->givePermissionTo(Permission::findOrCreate('bms.update', 'web'));

    $this->localArea = ProtectedArea::create([
        'name' => 'Fictional Local Area', 'short_name' => 'FLA', 'category' => 'Protected Landscape',
        'municipality' => 'Mati', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $this->focal->id, 'updated_by' => $this->focal->id,
    ]);
    $this->otherArea = ProtectedArea::create([
        'name' => 'Fictional Other Area', 'short_name' => 'FOA', 'category' => 'Protected Landscape',
        'municipality' => 'Baganga', 'province' => 'Davao Oriental', 'region' => 'Region XI',
        'created_by' => $this->focal->id, 'updated_by' => $this->focal->id,
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $this->localArea->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_mati')->value('id'),
    ]);
    ProtectedAreaOfficeAssignment::create([
        'protected_area_id' => $this->otherArea->id,
        'organizational_office_id' => OrganizationalOffice::query()->where('code', 'cenro_baganga')->value('id'),
    ]);

    $this->localRecord = makeMobileTrackingRecord($this->localArea, 'Mobile API local report');
    $this->secondLocalRecord = makeMobileTrackingRecord($this->localArea, 'Mobile API local report two');
    $this->otherRecord = makeMobileTrackingRecord($this->otherArea, 'Mobile API out-of-scope report');
});

function mobileTrackingLogin(User $user, string $password = 'fictional-mobile-password'): string
{
    return test()->postJson('/api/mobile/login', ['email' => $user->email, 'password' => $password])
        ->assertOk()->json('token');
}

function makeMobileTrackingRecord(ProtectedArea $area, string $name): BmsReportSubmission
{
    return BmsReportSubmission::create([
        'protected_area_id' => $area->id, 'target_office' => 'CENRO Mati',
        'activity_name' => $name, 'document_type' => 'Quarterly Report',
        'semester' => '1st Semester', 'date_accomplished' => '2026-08-03',
    ]);
}

it('requires an active web account and grants read ability only to tracking-authorized accounts', function (): void {
    $token = mobileTrackingLogin($this->focal);
    $stored = MobileApiToken::query()->find(explode('|', $token)[0]);
    expect($stored?->abilities)->toContain('mobile:auth', 'submission-tracking:read');

    $inactive = User::factory()->create(['email' => 'inactive@example.test', 'is_active' => false]);
    $this->postJson('/api/mobile/login', ['email' => $inactive->email, 'password' => 'password'])->assertForbidden();
    $unapproved = User::factory()->create(['email' => 'unapproved@example.test', 'is_active' => true, 'is_approved' => false, 'email_verified_at' => null]);
    $unapprovedToken = $this->postJson('/api/mobile/login', ['email' => $unapproved->email, 'password' => 'password'])
        ->assertOk()->json('token');
    $this->withToken($unapprovedToken)->getJson('/api/mobile/me')->assertOk()
        ->assertJsonPath('user.email', 'unapproved@example.test');

    $unauthorized = User::factory()->create(['email' => 'no-scope@example.test', 'section' => 'unknown']);
    $authOnly = mobileTrackingLogin($unauthorized, 'password');
    expect(MobileApiToken::query()->find(explode('|', $authOnly)[0])->abilities)->toBe(['mobile:auth']);
    $this->withToken($authOnly)->getJson('/api/mobile/submission-tracking/incoming')->assertForbidden();
});

it('locks mobile credentials after five attempts using the shared email and IP key', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/mobile/login', [
            'email' => $this->focal->email,
            'password' => 'incorrect-fictional-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/mobile/login', [
        'email' => $this->focal->email,
        'password' => 'fictional-mobile-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->postJson('https://cds-system.test/api/mobile/login', [
        'email' => $this->focal->email,
        'password' => 'fictional-mobile-password',
    ])->assertOk();
});

it('clears the shared login attempt count after valid credentials', function (): void {
    $email = $this->focal->email;
    $key = mb_strtolower($email).'|127.0.0.1';

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->postJson('/api/mobile/login', [
            'email' => $email,
            'password' => 'incorrect-fictional-password',
        ])->assertUnprocessable();
    }
    expect(RateLimiter::attempts($key))->toBe(3);

    $this->postJson('/api/mobile/login', [
        'email' => $email,
        'password' => 'fictional-mobile-password',
    ])->assertOk();
    expect(RateLimiter::attempts($key))->toBe(0);
});
it('returns Incoming, Outgoing and History with web filters and pagination', function (): void {
    $token = mobileTrackingLogin($this->focal);

    $response = $this->withToken($token)->getJson('/api/mobile/submission-tracking/incoming?search=local&per_page=1')
        ->assertOk()->assertJsonPath('meta.queue', 'incoming')->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.activity_name', 'Mobile API local report')
        ->assertJsonMissing(['activity_name' => 'Mobile API out-of-scope report']);
    $this->withToken($token)->getJson('/api/mobile/submission-tracking/incoming?search=local&per_page=1&page=2')
        ->assertOk()->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('data.0.activity_name', 'Mobile API local report two');

    $this->withToken($token)->getJson('/api/mobile/submission-tracking/outgoing')->assertOk()->assertJsonPath('meta.queue', 'outgoing');
    $this->withToken($token)->getJson('/api/mobile/submission-tracking/history')->assertOk()->assertJsonPath('meta.queue', 'history');
    expect($response->json('meta.per_page'))->toBe(1);
});

it('honors the existing global administrator visibility policy across offices', function (): void {
    $admin = User::factory()->create([
        'email' => 'mobile-global-admin@example.test',
        'section' => 'CDS',
        'unit_assignment' => null,
        'office_designated' => null,
    ]);
    $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    $token = mobileTrackingLogin($admin, 'password');

    $this->withToken($token)->getJson('/api/mobile/submission-tracking/incoming')
        ->assertOk()->assertJsonFragment(['activity_name' => 'Mobile API local report'])
        ->assertJsonFragment(['activity_name' => 'Mobile API out-of-scope report']);
    $this->withToken($token)->getJson('/api/mobile/submission-tracking/documents/bms/'.$this->otherRecord->id)
        ->assertOk()->assertJsonPath('data.activity_name', 'Mobile API out-of-scope report');
});

it('enforces Protected Area scope in queue and detail results', function (): void {
    $token = mobileTrackingLogin($this->focal);

    $this->withToken($token)->getJson('/api/mobile/submission-tracking/incoming')
        ->assertOk()->assertJsonFragment(['activity_name' => 'Mobile API local report'])
        ->assertJsonMissing(['activity_name' => 'Mobile API out-of-scope report']);
    $this->withToken($token)->getJson('/api/mobile/submission-tracking/documents/bms/'.$this->otherRecord->id)->assertNotFound();
});

it('returns authorized details without attachments or routing mutation affordances', function (): void {
    $token = mobileTrackingLogin($this->focal);

    $this->withToken($token)->getJson('/api/mobile/submission-tracking/documents/bms/'.$this->localRecord->id)
        ->assertOk()->assertJsonPath('data.source', 'bms')
        ->assertJsonPath('data.activity_name', 'Mobile API local report')
        ->assertJsonMissingPath('data.mov_url')
        ->assertJsonMissingPath('data.mov_attachment')
        ->assertJsonMissingPath('data.routing.actions');

    $this->withToken($token)->postJson('/api/mobile/submission-tracking/bms/'.$this->localRecord->id.'/forward')->assertNotFound();
});

it('revokes the mobile token on logout and denies later reads', function (): void {
    $token = mobileTrackingLogin($this->focal);
    $id = explode('|', $token)[0];

    $this->withToken($token)->postJson('/api/mobile/logout')->assertOk();
    expect(MobileApiToken::query()->find($id))->toBeNull();
    $this->withToken($token)->getJson('/api/mobile/me')->assertUnauthorized();
    $this->withToken($token)->getJson('/api/mobile/submission-tracking/incoming')->assertUnauthorized();
});
