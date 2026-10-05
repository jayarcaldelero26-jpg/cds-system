<?php

namespace App\Services\Storage;

use App\Services\Archive\GoogleDriveArchiveGateway;
use App\Services\Archive\GoogleDriveDocumentArchiveGateway;
use Throwable;

class GoogleDriveStorageCapacityProvider implements StorageCapacityProvider
{
    public function __construct(private readonly GoogleDriveArchiveGateway $gateway) {}

    public function measure(): array
    {
        $measuredAt = now()->toIso8601String();
        try {
            if (! $this->gateway instanceof GoogleDriveDocumentArchiveGateway) return $this->unavailable($measuredAt, 'Google Drive capacity is unavailable for the configured archive driver.');
            $quota = $this->gateway->storageQuota();
            $limitReported = array_key_exists('limit', $quota);
            $limit = $limitReported && is_numeric($quota['limit']) ? (int) $quota['limit'] : null;
            $usage = is_numeric($quota['usage'] ?? null) ? max(0, (int) $quota['usage']) : null;
            if ($usage === null) return $this->unavailable($measuredAt, 'Google Drive did not report storage usage.');
            if (! $limitReported) {
                return [
                    'provider' => 'google_drive', 'status' => 'available', 'used_bytes' => $usage,
                    'total_bytes' => null, 'free_bytes' => null, 'used_percentage' => null,
                    'measured_at' => $measuredAt, 'source' => 'google_drive_about_storage_quota',
                    'message' => 'Google Drive reported usage without a storage limit; free capacity and percentage are unavailable.',
                ];
            }
            if ($limit === null || $limit <= 0) return $this->unavailable($measuredAt, 'Google Drive did not report a finite storage capacity.');
            $free = max(0, $limit - $usage);
            return ['provider' => 'google_drive', 'status' => 'available', 'used_bytes' => $usage, 'total_bytes' => $limit, 'free_bytes' => $free, 'used_percentage' => (int) round(($usage / $limit) * 100), 'measured_at' => $measuredAt, 'source' => 'google_drive_about_storage_quota', 'message' => 'Capacity retrieved from the authenticated Google Drive account quota.'];
        } catch (Throwable) {
            return $this->unavailable($measuredAt, 'Google Drive capacity could not be retrieved safely.');
        }
    }

    private function unavailable(string $measuredAt, string $message): array
    {
        return ['provider' => 'google_drive', 'status' => 'unavailable', 'used_bytes' => null, 'total_bytes' => null, 'free_bytes' => null, 'used_percentage' => null, 'measured_at' => $measuredAt, 'source' => 'provider_unavailable', 'message' => $message];
    }
}
