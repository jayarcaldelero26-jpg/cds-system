import { useRef, useState } from 'react';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { markNotificationRead, shouldFollowNotificationDestination } from '@/Utils/notificationActions.mjs';

const filters = [['all', 'All'], ['unread', 'Unread'], ['overdue', 'Overdue'], ['due_soon', 'Due Soon'], ['submission_updates', 'Submission Updates']];
const tone = severity => severity === 'danger' ? 'text-red-600 dark:text-red-400' : severity === 'warning' ? 'text-amber-600 dark:text-amber-400' : severity === 'success' ? 'text-green-600 dark:text-green-400' : 'text-blue-600 dark:text-blue-400';
const icon = severity => severity === 'danger' ? 'solar:danger-triangle-bold' : severity === 'warning' ? 'solar:clock-circle-bold' : severity === 'success' ? 'solar:check-circle-bold' : 'solar:bell-bing-bold';

export default function NotificationsIndex({ notifications, filter }) {
    const [actionError, setActionError] = useState(null);
    const [pendingIds, setPendingIds] = useState(new Set());
    const otherPending = useRef(new Set());

    const recover = () => window.location.reload();
    const onFailure = failure => setActionError({
        kind: failure.kind,
        message: failure.kind === 'expired'
            ? 'Your session needs to be refreshed before this notification can be opened.'
            : 'The notification action could not be confirmed. Reload to check its current status.',
    });

    const markRead = (notification, destination = null) => markNotificationRead(router, notification.id, {
        onStart: () => setPendingIds(previous => new Set(previous).add(String(notification.id))),
        onSuccess: page => {
            setActionError(null);
            window.dispatchEvent(new Event('cds:notifications-updated'));
            if (destination && shouldFollowNotificationDestination(page)) router.visit(destination);
        },
        onFailure,
        onFinish: () => setPendingIds(previous => {
            const next = new Set(previous);
            next.delete(String(notification.id));
            return next;
        }),
    });

    const markUnread = notification => {
        const id = String(notification.id);
        if (otherPending.current.has(id)) return;
        otherPending.current.add(id);
        setPendingIds(previous => new Set(previous).add(id));
        router.patch(`/notifications/${encodeURIComponent(id)}/unread`, {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setActionError(null);
                window.dispatchEvent(new Event('cds:notifications-updated'));
            },
            onHttpException: response => { onFailure({ kind: response?.status === 419 ? 'expired' : 'http' }); return false; },
            onNetworkError: () => { onFailure({ kind: 'network' }); return false; },
            onFinish: () => {
                otherPending.current.delete(id);
                setPendingIds(previous => {
                    const next = new Set(previous);
                    next.delete(id);
                    return next;
                });
            },
        });
    };

    const markAllRead = () => router.post('/notifications/read-all', {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            setActionError(null);
            window.dispatchEvent(new Event('cds:notifications-updated'));
        },
        onHttpException: response => { onFailure({ kind: response?.status === 419 ? 'expired' : 'http' }); return false; },
        onNetworkError: () => { onFailure({ kind: 'network' }); return false; },
    });

    return <AuthenticatedLayout title="Notifications">
        <div className="space-y-6">
            <PageHeader title="Notifications" description="System alerts, report deadlines, and submission updates" actions={<button type="button" onClick={markAllRead} className="rounded-lg bg-white/15 px-3 py-2 text-sm font-semibold hover:bg-white/25" data-cds-action="true" data-cds-action-variant="primary">Mark all as read</button>} />
            {actionError && <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                <p>{actionError.message}</p>
                <button type="button" onClick={recover} className="font-bold underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600">{actionError.kind === 'expired' ? 'Reload to sign in again' : 'Reload to check status'}</button>
            </div>}
            <div className="flex flex-wrap gap-2" role="group" aria-label="Notification filters">{filters.map(([value, label]) => <button key={value} type="button" aria-pressed={filter === value} onClick={() => router.get('/notifications', { filter: value }, { preserveState: true, preserveScroll: true, replace: true })} className={`rounded-full px-3 py-1.5 text-sm font-semibold ${filter === value ? 'cds-tab-active' : 'border border-gray-200 bg-white text-gray-600 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800'}`}>{label}</button>)}</div>
            <section className="cds-card-surface overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                {notifications.length ? notifications.map(notification => <div key={notification.id} className={`flex gap-4 border-b border-gray-100 px-5 py-4 last:border-0 dark:border-gray-800 ${!notification.read_at ? 'bg-green-50/60 dark:bg-green-950/20' : ''}`}>
                    <Icon icon={icon(notification.severity)} width="22" height="22" className={`mt-0.5 shrink-0 ${tone(notification.severity)}`} />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div><h2 className="font-semibold text-gray-900 dark:text-white">{notification.title}</h2><p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{notification.message}</p><p className="mt-2 text-xs text-gray-500">{notification.source_label}{notification.office ? ` · ${notification.office}` : ''}</p></div>
                            <div className="flex flex-wrap items-center gap-2">
                                {!notification.read_at && <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300">Unread</span>}
                                {notification.current_state && <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">{notification.current_state}</span>}
                            </div>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <button type="button" disabled={pendingIds.has(String(notification.id))} aria-busy={pendingIds.has(String(notification.id)) || undefined} onClick={() => notification.read_at ? markUnread(notification) : markRead(notification)} className="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-700 disabled:cursor-wait disabled:opacity-70 dark:border-gray-700 dark:text-gray-200" data-cds-action="true" data-cds-action-variant="primary">Mark as {notification.read_at ? 'unread' : 'read'}</button>
                            {notification.url && <button type="button" disabled={pendingIds.has(String(notification.id))} aria-busy={pendingIds.has(String(notification.id)) || undefined} onClick={() => markRead(notification, notification.url)} className="rounded-lg bg-green-700 px-2.5 py-1.5 text-xs font-semibold text-white disabled:cursor-wait disabled:opacity-70" data-cds-action="true" data-cds-action-variant="primary">View</button>}
                        </div>
                    </div>
                </div>) : <div className="px-6 py-12 text-center text-sm text-gray-500">No notifications match this filter.</div>}
            </section>
        </div>
    </AuthenticatedLayout>;
}
