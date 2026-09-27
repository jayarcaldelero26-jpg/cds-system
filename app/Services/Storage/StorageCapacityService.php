<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Cache;

final class StorageCapacityService
{
    private const CACHE_KEY = 'settings.storage_capacity.v1';
    private const CACHE_SECONDS = 600;

    public function __construct(private readonly ServerStorageCapacityProvider $server, private readonly GoogleDriveStorageCapacityProvider $googleDrive) {}

    public function current(bool $refresh = false): array
    {
        if ($refresh) Cache::forget(self::CACHE_KEY);
        $decorate = fn (array $item): array => [
            ...$item,
            'used_label' => StorageCapacityFormatter::bytes($item['used_bytes'] ?? null),
            'free_label' => StorageCapacityFormatter::bytes($item['free_bytes'] ?? null),
            'total_label' => StorageCapacityFormatter::bytes($item['total_bytes'] ?? null),
        ];
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => ['providers' => ['server' => $this->server->measure(), 'google_drive' => $this->googleDrive->measure()], 'cached_until' => now()->addSeconds(self::CACHE_SECONDS)->toIso8601String(), 'cache_seconds' => self::CACHE_SECONDS]);
        $data['providers'] = array_map($decorate, $data['providers']);
        return $data;
    }

    public function refresh(): array
    {
        return $this->current(true);
    }
}
