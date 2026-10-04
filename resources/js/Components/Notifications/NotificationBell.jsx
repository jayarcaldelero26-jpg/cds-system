import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import UtilityIconButton from '@/Components/UtilityIconButton';

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

export function NotificationPanel({ state, requestError = '', onRetry, onOpen, onClear }) {
    const unread = Number(state?.unread_count || 0);
    return <div className="cds-menu-panel absolute right-0 z-50 mt-2 w-[22rem] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
        <div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <p className="text-sm font-bold text-gray-900 dark:text-white">Notifications</p>
            <p className="text-xs text-gray-500 dark:text-gray-400">{unread ? unread + ' notification' + (unread === 1 ? '' : 's') : 'No new notifications.'}</p>
        </div>
        <div className="max-h-[26rem] overflow-y-auto">
            {requestError && <div role="alert" className="border-b border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"><p>{requestError}</p><button type="button" onClick={onRetry} className="mt-2 font-bold underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600">Retry</button></div>}
            {state?.notifications?.length ? state.notifications.map(notification => <button type="button" key={notification.id} onClick={() => onOpen(notification)} className="flex w-full items-start justify-start gap-3 border-b border-gray-100 px-4 py-3 text-left text-sm text-gray-800 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-600 dark:border-gray-800 dark:text-gray-100 dark:hover:bg-gray-800">
                <Icon icon={icon(notification.severity)} width="19" height="19" className={'mt-0.5 shrink-0 ' + tone(notification.severity)} />
                <span className="min-w-0 flex-1">
                    <span className="flex items-start justify-between gap-2"><strong className="text-sm text-gray-900 dark:text-white">{notification.title}</strong><i className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-red-400" /></span>
                    <span className="mt-1 block text-xs leading-5 text-gray-700 dark:text-gray-200">{notification.message}</span>
                    <span className="mt-1 block text-[11px] text-gray-500 dark:text-gray-400">{relativeTime(notification.created_at)}</span>
                </span>
            </button>) : !requestError && <div className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No new notifications.</div>}
        </div>
        <div className="border-t border-gray-100 p-2 dark:border-gray-800">
            <button type="button" onClick={onClear} className="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white">Clear Notifications</button>
        </div>
    </div>;
}

export default function NotificationBell({ initial = { unread_count: 0, notifications: [] } }) {
    const [open, setOpen] = useState(false);
    const [state, setState] = useState(initial);
    const [requestError, setRequestError] = useState('');
    const root = useRef(null);

    const refresh = async () => {
        try {
            const response = await fetch('/notifications/recent', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('request-failed');
            const payload = await response.json();
            setState(payload);
            setRequestError('');
            return true;
        } catch {
            setRequestError('Notifications could not be loaded. Check your connection and try again.');
            return false;
        }
    };

    useEffect(() => {
        const timer = window.setInterval(refresh, 60000);
        const sync = () => { refresh(); };
        const close = event => { if (root.current && !root.current.contains(event.target)) setOpen(false); };
        window.addEventListener('cds:notifications-updated', sync);
        document.addEventListener('mousedown', close);
        return () => { window.clearInterval(timer); window.removeEventListener('cds:notifications-updated', sync); document.removeEventListener('mousedown', close); };
    }, []);

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const request = async (url, method = 'PATCH') => {
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
            });
            if (!response.ok) throw new Error('request-failed');
            const payload = await response.json().catch(() => null);
            if (payload && Object.prototype.hasOwnProperty.call(payload, 'notifications')) setState(payload);
            else if (!await refresh()) return false;
            setRequestError('');
            return true;
        } catch {
            setRequestError('The notification action could not be confirmed. Refresh to check its current status before retrying.');
            return false;
        }
    };

    const openNotification = async notification => {
        if (!await request('/notifications/' + notification.id + '/read')) return;
        setOpen(false);
        if (notification.url) router.visit(notification.url);
    };

    const unread = Number(state.unread_count || 0);
    const badge = unread > 9 ? '9+' : unread;

    return <div ref={root} className="relative">
        <UtilityIconButton onClick={() => { setOpen(value => !value); refresh(); }} className="relative p-2 text-gray-500 hover:text-gray-700 dark:text-gray-300" aria-label="Notifications" aria-expanded={open}>
            <Icon icon="solar:bell-linear" width="22" height="22" />
            {unread > 0 && <span className="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{badge}</span>}
        </UtilityIconButton>
        {open && <NotificationPanel state={state} requestError={requestError} onRetry={refresh} onOpen={openNotification} onClear={() => request('/notifications/clear', 'POST')} />}
    </div>;
}
