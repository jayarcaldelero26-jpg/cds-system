<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

final class AuditLogService
{
    /** @param array<string, mixed> $metadata */
    public function record(
        string $eventType,
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?string $module = null,
        ?string $summary = null,
        array $metadata = [],
        ?int $userId = null,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();
        unset($metadata['password'], $metadata['current_password'], $metadata['token']);

        return AuditLog::query()->create([
            'event_type' => $eventType,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'module' => $module,
            'summary' => $summary,
            'metadata' => $metadata,
            'user_id' => $userId ?? $request->user()?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
