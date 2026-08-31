<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->select(['id', 'event_type', 'action', 'module', 'entity_type', 'entity_id', 'summary', 'user_id', 'created_at'])
            ->with('user:id,name')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where(function ($inner) use ($search): void {
                    $inner->where('action', 'like', "%{$search}%")
                        ->orWhere('event_type', 'like', "%{$search}%")
                        ->orWhere('module', 'like', "%{$search}%")
                        ->orWhere('summary', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')->toString()))
            ->when($request->filled('actor'), fn ($query) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', '%'.$request->string('actor')->toString().'%')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('date_to')))
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'action' => $log->action,
                'module' => $log->module,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'summary' => $log->summary,
                'actor' => $log->user?->name ?? 'System',
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $request->only(['search', 'event_type', 'actor', 'date_from', 'date_to']),
            'eventTypes' => AuditLog::query()->distinct()->orderBy('event_type')->pluck('event_type')->values(),
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_unless($request->user()?->can('audit-logs.view'), 403);

        return response()->json([
            'id' => $auditLog->id,
            'event_type' => $auditLog->event_type,
            'action' => $auditLog->action,
            'module' => $auditLog->module,
            'entity_type' => $auditLog->entity_type,
            'entity_id' => $auditLog->entity_id,
            'summary' => $auditLog->summary,
            'metadata' => $this->redact($auditLog->metadata ?? []),
            'actor' => $auditLog->user?->name ?? 'System',
            'created_at' => $auditLog->created_at?->toIso8601String(),
            'updated_at' => $auditLog->updated_at?->toIso8601String(),
            'ip_address' => $auditLog->ip_address,
            'user_agent' => $auditLog->user_agent,
        ]);
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sensitive = ['password', 'current_password', 'app_key', 'db_password', 'token', 'session'];
        $result = [];

        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $sensitive, true) || str_contains($normalizedKey, 'credential')) {
                continue;
            }
            $result[$key] = $this->redact($item);
        }

        return $result;
    }
}
