<?php

namespace App\Services\Storage;

use Throwable;

class ServerStorageCapacityProvider implements StorageCapacityProvider
{
    public function measure(): array
    {
        $measuredAt = now()->toIso8601String();
        try {
            $path = base_path();
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (! is_numeric($total) || ! is_numeric($free) || (float) $total <= 0) return $this->unavailable($measuredAt, 'Filesystem capacity is not available in this runtime.');
            $totalBytes = (int) $total;
            $freeBytes = max(0, min($totalBytes, (int) $free));
            $usedBytes = max(0, $totalBytes - $freeBytes);
            return ['provider' => 'server', 'status' => 'available', 'used_bytes' => $usedBytes, 'total_bytes' => $totalBytes, 'free_bytes' => $freeBytes, 'used_percentage' => (int) round(($usedBytes / $totalBytes) * 100), 'measured_at' => $measuredAt, 'source' => 'local_filesystem', 'message' => 'Filesystem capacity; hosting-plan quota is not exposed by this runtime.'];
        } catch (Throwable) {
            return $this->unavailable($measuredAt, 'Filesystem capacity could not be measured safely.');
        }
    }

    private function unavailable(string $measuredAt, string $message): array
    {
        return ['provider' => 'server', 'status' => 'unavailable', 'used_bytes' => null, 'total_bytes' => null, 'free_bytes' => null, 'used_percentage' => null, 'measured_at' => $measuredAt, 'source' => 'runtime_unavailable', 'message' => $message];
    }
}
