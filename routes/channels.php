<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}.live-refresh', static function (User $user, int $userId): bool {
    return (int) $user->getKey() === $userId
        && (bool) $user->is_active
        && (bool) $user->is_approved;
});
