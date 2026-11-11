import { Icon } from '@iconify/react';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import UtilityIconButton from '@/Components/UtilityIconButton';
import { markNotificationRead, shouldFollowNotificationDestination } from '@/Utils/notificationActions.mjs';
import {
    loadNotificationToastSeenIds,
    notificationToastStorageKey,
    persistNotificationToastSeenIds,
    takeNewReportNotifications,
} from '@/Utils/notificationToastDedup.mjs';

const relativeTime = value => {
    const timestamp = value ? Date.parse(value) : Number.NaN;
    if (Number.isNaN(timestamp)) return '';
    const minutes = Math.max(0, Math.round((Date.now() - timestamp) / 60000));
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return minutes + 'm ago';
    if (minutes < 1440) return Math.floor(minutes / 60) + 'h ago';
    return Math.floor(minutes / 1440) + 'd ago';
};

const tone = severity => severity === 'danger' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400';
const icon = severity => severity === 'danger' ? 'solar:danger-triangle-bold' : 'solar:clock-circle-bold';
const currentSessionStorage = () => { try { return globalThis.sessionStorage; } catch { return null; } };

export function NotificationPanel({ state, requestError = '', recoveryLabel, onRecover, onRetry, onOpen, onClear, pendingIds = new Set() }) {
    const unread = Number(state?.unread_count || 0);
    return <div className="cds-menu-panel absolute right-0 z-50 mt-2 w-[22rem] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
        <div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <p className="text-sm font-bold text-gray-900 dark:text-white">Notifications</p>
            <p className="text-xs text-gray-500 dark:text-gray-400">{unread ? unread + ' notification' + (unread === 1 ? '' : 's') : 'No new notifications.'}</p>
        </div>
        <div className="max-h-[26rem] overflow-y-auto">
            {requestError && <div role="alert" className="border-b border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"><p>{requestError}</p><button type="button" onClick={onRecover || onRetry} className="mt-2 font-bold underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600">{recoveryLabel || 'Retry'}</button></div>}
            {state?.notifications?.length ? state.notifications.map(notification => <button type="button" key={notification.id} disabled={pendingIds.has(String(notification.id))} aria-busy={pendingIds.has(String(notification.id)) || undefined} onClick={() => onOpen(notification)} className="flex w-full items-start justify-start gap-3 border-b border-gray-100 px-4 py-3 text-left text-sm text-gray-800 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600 disabled:cursor-wait disabled:opacity-70 dark:border-gray-800 dark:text-gray-100 dark:hover:bg-gray-800">
                <Icon icon={icon(notification.severity)} width="19" height="19" className={'mt-0.5 shrink-0 ' + tone(notification.severity)} />
                <span className="min-w-0 flex-1">
                    <span className="flex items-start justify-between gap-2"><strong className="text-sm text-gray-900 dark:text-white">{notification.title}</strong><i className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-red-400" /></span>
                    <span className="mt-1 block text-xs leading-5 text-gray-700 dark:text-gray-200">{notification.message}</span>
                    {notification.current_state && <span className="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">{notification.current_state}</span>}
                    <span className="mt-1 block text-[11px] text-gray-500 dark:text-gray-400">{relativeTime(notification.created_at)}</span>
                </span>
            </button>) : !requestError && <div className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No new notifications.</div>}
        </div>
        <div className="border-t border-gray-100 p-2 dark:border-gray-800">
            <button type="button" onClick={onClear} className="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white">Clear Notifications</button>
        </div>
    </div>;
}

function NotificationToast({ notification, busy = false, onDismiss, onView }) {
    if (!notification) return null;

    return <div role="status" aria-live="polite" aria-atomic="true" className="fixed bottom-4 right-4 z-[100] w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-green-200 bg-white p-4 shadow-2xl dark:border-green-900 dark:bg-gray-900">
        <div className="flex items-start gap-3">
            <Icon icon="solar:bell-bing-linear" width="20" height="20" className="mt-0.5 shrink-0 text-green-700 dark:text-green-300" aria-hidden="true" />
            <div className="min-w-0 flex-1">
                <p className="text-sm font-bold text-gray-900 dark:text-white">{notification.title}</p>
                <p className="mt-1 text-xs leading-5 text-gray-700 dark:text-gray-200">{notification.message}</p>
                {notification.current_state && <p className="mt-1 text-[11px] font-semibold text-amber-800 dark:text-amber-200">{notification.current_state}</p>}
                <div className="mt-3 flex items-center gap-3">
                    <button type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => onView(notification)} className="rounded-md bg-green-700 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-70">{busy ? 'Opening…' : 'View'}</button>
                    <button type="button" onClick={() => onDismiss(notification.id)} className="rounded-md px-2 py-1.5 text-xs font-semibold text-gray-600 underline underline-offset-2 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-300 dark:hover:text-white">Dismiss</button>
                </div>
            </div>
            <button type="button" onClick={() => onDismiss(notification.id)} aria-label="Dismiss notification pop-up" className="rounded-md p-1 text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-400 dark:hover:bg-gray-800">
                <Icon icon="solar:close-circle-linear" width="18" height="18" aria-hidden="true" />
            </button>
        </div>
    </div>;
}

export default function NotificationBell({ initial = { unread_count: 0, notifications: [] } }) {
    const { auth = {} } = usePage().props;
    const [open, setOpen] = useState(false);
    const [state, setState] = useState(initial);
    const [toasts, setToasts] = useState([]);
    const [requestError, setRequestError] = useState('');
    const [requestErrorMode, setRequestErrorMode] = useState('');
    const [pendingIds, setPendingIds] = useState(new Set());
    const root = useRef(null);
    const userId = auth?.user?.id;
    const seenToastIds = useRef(new Set());
    const toastStorageKey = useRef(notificationToastStorageKey(userId));
    const initializedAt = useRef(initial.initialized_at || new Date().toISOString());
    const refreshInFlight = useRef(null);
    const refreshPending = useRef(false);

    const refresh = useCallback(() => {
        if (refreshInFlight.current) {
            refreshPending.current = true;
            return refreshInFlight.current;
        }

        const request = (async () => {
            try {
                const response = await fetch('/notifications/recent', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('request-failed');
                const payload = await response.json();
                const seen = seenToastIds.current;
                const freshToasts = takeNewReportNotifications(payload, seen, initializedAt.current);
                if (freshToasts.length) {
                    setToasts(previous => {
                        const queuedIds = new Set(previous.map(item => String(item.id)));
                        return [...previous, ...freshToasts.filter(item => !queuedIds.has(String(item.id)))];
                    });
                }
                persistNotificationToastSeenIds(currentSessionStorage(), toastStorageKey.current, seen);
                setState(payload);
                setRequestError('');
                setRequestErrorMode('');
                return true;
            } catch {
                setRequestError('Notifications could not be loaded. Check your connection and try again.');
                setRequestErrorMode('refresh');
                return false;
            }
        })();

        refreshInFlight.current = request;
        request.finally(() => {
            if (refreshInFlight.current === request) refreshInFlight.current = null;
            if (refreshPending.current) {
                refreshPending.current = false;
                void refresh();
            }
        });
        return request;
    }, []);

    useEffect(() => {
        const key = notificationToastStorageKey(userId);
        toastStorageKey.current = key;
        seenToastIds.current = loadNotificationToastSeenIds(currentSessionStorage(), userId, initial.notifications || []);
        initializedAt.current = initial.initialized_at || new Date().toISOString();
        setState(initial);
        setToasts([]);
        void refresh();
    }, [userId]);

    useEffect(() => {
        const sync = () => { void refresh(); };
        const close = event => { if (root.current && !root.current.contains(event.target)) setOpen(false); };
        window.addEventListener('cds:notifications-updated', sync);
        document.addEventListener('mousedown', close);
        return () => { window.removeEventListener('cds:notifications-updated', sync); document.removeEventListener('mousedown', close); };
    }, [refresh]);

    useEffect(() => setState(initial), [initial]);

    const recoverAction = () => window.location.reload();
    const handleActionFailure = failure => {
        setRequestError(failure.kind === 'expired'
            ? 'Your session needs to be refreshed before this notification can be opened.'
            : 'The notification action could not be confirmed. Reload to check its current status.');
        setRequestErrorMode(failure.kind === 'expired' ? 'expired' : 'action');
        setOpen(true);
    };

    const openNotification = notification => {
        markNotificationRead(router, notification.id, {
            onStart: () => setPendingIds(previous => new Set(previous).add(String(notification.id))),
            onSuccess: page => {
                setOpen(false);
                setToasts(previous => previous.filter(item => String(item.id) !== String(notification.id)));
                setRequestError('');
                setRequestErrorMode('');
                window.dispatchEvent(new Event('cds:notifications-updated'));
                if (notification.url && shouldFollowNotificationDestination(page)) router.visit(notification.url);
            },
            onFailure: handleActionFailure,
            onFinish: () => setPendingIds(previous => {
                const next = new Set(previous);
                next.delete(String(notification.id));
                return next;
            }),
        });
    };

    const dismissToast = notificationId => setToasts(previous => previous.filter(item => String(item.id) !== String(notificationId)));
    const clearNotifications = () => router.post('/notifications/clear', {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            setToasts([]);
            setRequestError('');
            setRequestErrorMode('');
            window.dispatchEvent(new Event('cds:notifications-updated'));
        },
        onHttpException: response => { handleActionFailure({ kind: response?.status === 419 ? 'expired' : 'http' }); return false; },
        onNetworkError: () => { handleActionFailure({ kind: 'network' }); return false; },
    });

    const unread = Number(state.unread_count || 0);
    const badge = unread > 9 ? '9+' : unread;

    return <div ref={root} className="relative">
        <UtilityIconButton onClick={() => { setOpen(value => !value); refresh(); }} className="relative p-2 text-gray-500 hover:text-gray-700 dark:text-gray-300" aria-label="Notifications" aria-expanded={open}>
            <Icon icon="solar:bell-linear" width="22" height="22" />
            {unread > 0 && <span className="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{badge}</span>}
        </UtilityIconButton>
        {open && <NotificationPanel state={state} requestError={requestError} recoveryLabel={requestErrorMode === 'expired' ? 'Reload to sign in again' : requestErrorMode === 'action' ? 'Reload to check status' : 'Retry'} onRecover={requestErrorMode ? recoverAction : undefined} onRetry={refresh} onOpen={openNotification} onClear={clearNotifications} pendingIds={pendingIds} />}
        <NotificationToast notification={toasts[0]} busy={pendingIds.has(String(toasts[0]?.id))} onDismiss={dismissToast} onView={openNotification} />
    </div>;
}
