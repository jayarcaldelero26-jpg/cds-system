<?php

namespace App\Services\SubmissionTracking;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Prevents overlapping initial PENRO Records archive checkpoints per source record. */
final class DispatchInProgressGuard
{
    private const LOCK_TTL_SECONDS = 1800;

    private const SHARED_LOCK_DRIVERS = ['file', 'database', 'redis', 'memcached', 'dynamodb'];

    private const INITIAL_DISPATCH_ACTIONS = [
        'forward_to_office_penro',
        'dispatch_penro_records_to_tsd',
        'dispatch_penro_records_to_cds_focal',
    ];

    public function protects(string $actionKey): bool
    {
        return in_array($actionKey, self::INITIAL_DISPATCH_ACTIONS, true);
    }

    public function acquire(string $sourceKey, int|string $recordId): Lock
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver", $store);
        if ($driver === 'array' && app()->environment('testing')) {
            // The in-memory store is useful for single-process feature tests.
            // Cross-worker concurrency coverage uses the atomic file store.
        } elseif (! in_array($driver, self::SHARED_LOCK_DRIVERS, true)) {
            throw new \RuntimeException('A shared atomic cache lock store is required for routing dispatches.');
        }

        $lock = Cache::lock($this->key($sourceKey, $recordId), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'stage' => 'Dispatch already in progress. Wait for the current archive checkpoint to finish, then retry if needed.',
            ]);
        }

        return $lock;
    }

    private function key(string $sourceKey, int|string $recordId): string
    {
        return 'submission-routing:initial-dispatch:'.$sourceKey.':'.$recordId;
    }
}
