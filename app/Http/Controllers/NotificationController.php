<?php

namespace App\Http\Controllers;

use App\Services\Notifications\EdatsInAppNotificationService;
use App\Services\SubmissionTracking\IncomingUnreadCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationController extends Controller
{
    private const BELL_NOTIFICATION_TYPES = ['due_soon', 'overdue', 'workflow'];

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
        $unreadNotifications = $request->user()->unreadNotifications();
        $unreadCount = (clone $unreadNotifications)->whereIn('data->type', self::BELL_NOTIFICATION_TYPES)->count();
        $items = $unreadNotifications
            ->whereIn('data->type', self::BELL_NOTIFICATION_TYPES)
            ->latest()
            ->limit(8)
            ->get();
        $canViewTracking = $items->isNotEmpty() && $request->user()->can('submission-tracking.view');
        $presented = $this->presentNotifications($items, $request, $canViewTracking);

        return response()->json([
            'unread_count' => $unreadCount,
            'notifications' => $presented->values(),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse|RedirectResponse
    {
        abort_unless($notification->notifiable_type === $request->user()::class && (int) $notification->notifiable_id === (int) $request->user()->id, 404);
        $notification->markAsRead();
        $this->publishReadStateChanged($request);

        $incoming = $this->incomingNoticeTarget($notification, $request);
        if ($incoming !== null && ! app(IncomingUnreadCountService::class)->isCurrentIncomingAction(
            $request->user(),
            $incoming['source'],
            $incoming['source_id'],
            $incoming['arrival_event_id'],
        )) {
            $message = 'This report no longer needs your action.';

            return $request->expectsJson()
                ? response()->json(['ok' => true, 'incoming_status' => 'stale', 'message' => $message])
                : redirect()->route('submission-tracking.index', ['view' => 'incoming'])
                    ->with('status', $message)
                    ->with('notification_action', 'stale_incoming');
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** @return array{source:string,source_id:int,arrival_event_id:?int}|null */
    private function incomingNoticeTarget(DatabaseNotification $notification, Request $request): ?array
    {
        return EdatsInAppNotificationService::incomingActionTarget(
            $notification->data ?? [],
            $request->user()->can('submission-tracking.view'),
        );
    }

    public function markUnread(Request $request, DatabaseNotification $notification): JsonResponse|RedirectResponse
    {
        abort_unless($notification->notifiable_type === $request->user()::class && (int) $notification->notifiable_id === (int) $request->user()->id, 404);
        $notification->update(['read_at' => null]);
        $this->publishReadStateChanged($request);
        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->publishReadStateChanged($request);
        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function clear(Request $request): JsonResponse|RedirectResponse
    {
        $this->bellNotifications($request)->each->markAsRead();
        $this->publishReadStateChanged($request);

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'unread_count' => 0, 'notifications' => []])
            : back();
    }

    private function publishReadStateChanged(Request $request): void
    {
        app(\App\Services\LiveRefreshPublisher::class)->afterCommit(
            [(int) $request->user()->getKey()],
            ['notifications', 'tracking'],
        );
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function items(Request $request, string $filter)
    {
        $items = $request->user()->notifications()->latest()->get()
            ->filter(function (DatabaseNotification $notification) use ($filter): bool {
                $category = $notification->data['category'] ?? '';
                return match ($filter) {
                    'unread' => $notification->read_at === null,
                    'overdue' => $category === 'overdue',
                    'due_soon' => $category === 'due_soon',
                    'submission_updates' => $category === 'submission_updates',
                    default => true,
                };
            });
        $canViewTracking = $items->isNotEmpty() && $request->user()->can('submission-tracking.view');

        return $this->presentNotifications($items->values(), $request, $canViewTracking);
    }

    /** @return \Illuminate\Support\Collection<int, DatabaseNotification> */
    private function bellNotifications(Request $request)
    {
        return $request->user()->unreadNotifications()->latest()->get()
            ->filter(fn (DatabaseNotification $notification): bool => EdatsInAppNotificationService::isBellAlert($notification->data))
            ->values();
    }

    /**
     * @param Collection<int, DatabaseNotification> $notifications
     * @return Collection<int, array<string, mixed>>
     */
    private function presentNotifications(Collection $notifications, Request $request, bool $canViewTracking): Collection
    {
        $incomingTargets = [];
        foreach ($notifications as $notification) {
            $target = $this->incomingNoticeTarget($notification, $request);
            if ($target !== null) $incomingTargets[(string) $notification->id] = $target;
        }

        $incomingStatuses = $incomingTargets !== []
            ? app(IncomingUnreadCountService::class)->incomingActionStatuses($request->user(), $incomingTargets)
            : [];

        return $notifications->map(function (DatabaseNotification $notification) use ($canViewTracking, $incomingTargets, $incomingStatuses): array {
            $currentState = isset($incomingTargets[(string) $notification->id])
                && ! ($incomingStatuses[(string) $notification->id] ?? false)
                ? 'No longer in Incoming'
                : null;

            return $this->present($notification, $canViewTracking, $currentState);
        });
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $notification, bool $canViewTracking, ?string $currentState): array
    {
        $data = $notification->data;
        $url = $data['url'] ?? null;
        if (EdatsInAppNotificationService::isBellAlert($data)) {
            $url = EdatsInAppNotificationService::actionUrl($data, $canViewTracking);
        }

        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? 'System notification',
            'message' => $data['message'] ?? '',
            'severity' => $data['severity'] ?? 'info',
            'category' => $data['category'] ?? 'submission_updates',
            'source_label' => $data['source_label'] ?? 'Report',
            'office' => $data['office'] ?? null,
            'protected_area' => $data['protected_area'] ?? null,
            'current_state' => $currentState,
            'url' => $url,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->format('Y-m-d\\TH:i:s.uP'),
        ];
    }
}
