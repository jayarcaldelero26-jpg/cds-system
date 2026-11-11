<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class LiveRefreshRequested implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private readonly string $token;

    public function __construct(
        public readonly int $recipientId,
        public readonly string $scope,
    ) {
        $this->token = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->recipientId.'.live-refresh')];
    }

    public function broadcastAs(): string
    {
        return 'cds.live-refresh';
    }

    /** @return array{scope:string,token:string} */
    public function broadcastWith(): array
    {
        return ['scope' => $this->scope, 'token' => $this->token];
    }
}
