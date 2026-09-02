<?php

namespace App\Http\Controllers;

use App\Services\Notifications\EdatsInAppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = (string) $request->query('filter', 'all');
        $items = $this->items($request, $filter);

        return Inertia::render('Notifications/Index', [
            'notifications' => $items->values()->all(),
            'filter' => $filter,
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $items = $this->bellNotifications($request)->take(8)->map(fn (DatabaseNotification $notification) => $this->present($notification));

        return response()->json(['unread_count' => $items->count(), 'notifications' => $items->values()]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse|RedirectResponse
    {
        abort_unless($notification->notifiable_type === $request->user()::class && (int) $notification->notifiable_id === (int) $request->user()->id, 404);
        $notification->markAsRead();
        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markUnread(Request $request, DatabaseNotification $notification): JsonResponse|RedirectResponse
    {
        abort_unless($notification->notifiable_type === $request->user()::class && (int) $notification->notifiable_id === (int) $request->user()->id, 404);
        $notification->update(['read_at' => null]);
        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function clear(Request $request): JsonResponse|RedirectResponse
    {
        $this->bellNotifications($request)->each->markAsRead();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'unread_count' => 0, 'notifications' => []])
            : back();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function items(Request $request, string $filter)
    {
        return $request->user()->notifications()->latest()->get()
            ->filter(function (DatabaseNotification $notification) use ($filter): bool {
                $category = $notification->data['category'] ?? '';
                return match ($filter) {
                    'unread' => $notification->read_at === null,
                    'overdue' => $category === 'overdue',
                    'due_soon' => $category === 'due_soon',
                    'submission_updates' => $category === 'submission_updates',
                    default => true,
                };
            })
            ->map(fn (DatabaseNotification $notification) => $this->present($notification));
    }

    /** @return \Illuminate\Support\Collection<int, DatabaseNotification> */
    private function bellNotifications(Request $request)
    {
        return $request->user()->unreadNotifications()->latest()->get()
            ->filter(fn (DatabaseNotification $notification): bool => EdatsInAppNotificationService::isBellAlert($notification->data))
            ->values();
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? 'System notification',
            'message' => $data['message'] ?? '',
            'severity' => $data['severity'] ?? 'info',
            'category' => $data['category'] ?? 'submission_updates',
            'source_label' => $data['source_label'] ?? 'Report',
            'office' => $data['office'] ?? null,
            'protected_area' => $data['protected_area'] ?? null,
            'url' => $data['url'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
