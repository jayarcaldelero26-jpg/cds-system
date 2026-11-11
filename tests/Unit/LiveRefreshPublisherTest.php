<?php

use App\Events\LiveRefreshRequested;
use App\Services\LiveRefreshPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
});

test('live refresh events publish only after the outer transaction commits', function (): void {
    Event::fake([LiveRefreshRequested::class]);
    DB::beginTransaction();

    app(LiveRefreshPublisher::class)->afterCommit([41, 41, null, 'bad-id'], ['tracking', 'unsupported', 'archives']);

    Event::assertNotDispatched(LiveRefreshRequested::class);
    DB::commit();

    Event::assertDispatched(LiveRefreshRequested::class, fn (LiveRefreshRequested $event): bool =>
        $event->recipientId === 41 && $event->scope === 'tracking'
    );
    Event::assertDispatched(LiveRefreshRequested::class, fn (LiveRefreshRequested $event): bool =>
        $event->recipientId === 41 && $event->scope === 'archives'
    );
    Event::assertDispatchedTimes(LiveRefreshRequested::class, 2);
});

test('live refresh events are discarded when their transaction rolls back', function (): void {
    Event::fake([LiveRefreshRequested::class]);
    DB::beginTransaction();

    app(LiveRefreshPublisher::class)->afterCommit([41], ['notifications', 'tracking']);

    Event::assertNotDispatched(LiveRefreshRequested::class);
    DB::rollBack();

    Event::assertNotDispatched(LiveRefreshRequested::class);
});

test('live refresh broadcast carries only a scope and opaque token to the recipient private channel', function (): void {
    $event = new LiveRefreshRequested(41, 'tracking');

    expect($event->broadcastOn()[0]->name)->toBe('private-users.41.live-refresh')
        ->and($event->broadcastAs())->toBe('cds.live-refresh')
        ->and($event->broadcastWith())->toHaveKeys(['scope', 'token'])
        ->and($event->broadcastWith()['scope'])->toBe('tracking')
        ->and($event->broadcastWith()['token'])->toMatch('/^[0-9a-f-]{36}$/');
});
