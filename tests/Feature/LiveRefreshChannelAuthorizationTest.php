<?php

use App\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\DB;

test('the filtered isolated test migration path omits the pending September 9 migration', function (): void {
    if (! getenv('CDS_TEST_MIGRATIONS_PATH')) {
        $this->markTestSkipped('A filtered migration path was not requested for this test run.');
    }

    expect(DB::table('migrations')
        ->where('migration', '2026_09_09_010000_remove_report_reconciliation_and_completeness_metadata')
        ->exists())->toBeFalse();
});

test('private live refresh channel authorizes only its own active approved account', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-app-key',
        'broadcasting.connections.reverb.secret' => 'test-app-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);
    // The PHPUnit bootstrap defaults to the null broadcaster to prevent network writes.
    // Register the real private-channel callback on the in-memory Reverb driver for this auth test.
    require base_path('routes/channels.php');

    $owner = User::factory()->create(['is_active' => true, 'is_approved' => true]);
    $other = User::factory()->create(['is_active' => true, 'is_approved' => true]);
    $inactive = User::factory()->create(['is_active' => false, 'is_approved' => true]);
    $channel = "private-users.{$owner->id}.live-refresh";

    $this->actingAs($owner)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk();
    $this->actingAs($other)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertForbidden();
    $this->withoutMiddleware(HandleInertiaRequests::class)->actingAs($inactive)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-users.{$inactive->id}.live-refresh",
    ])->assertForbidden();
});
