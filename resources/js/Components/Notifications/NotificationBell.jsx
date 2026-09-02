import { Icon } from '@iconify/react';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

export default function NotificationBell({ initial = { unread_count: 0, notifications: [] } }) {
    const [open, setOpen] = useState(false);
    const [state, setState] = useState(initial);
    const root = useRef(null);

    const refresh = async () => {
        const response = await fetch('/notifications/recent', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (response.ok) setState(await response.json());
    };

    useEffect(() => {
        const timer = window.setInterval(refresh, 60000);
        const close = event => { if (root.current && !root.current.contains(event.target)) setOpen(false); };
        document.addEventListener('mousedown', close);
        return () => { window.clearInterval(timer); document.removeEventListener('mousedown', close); };
    }, []);

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const request = async (url, method = 'PATCH') => {
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
        if (response.ok) {
            const payload = await response.json().catch(() => null);
            if (payload && Object.prototype.hasOwnProperty.call(payload, 'notifications')) setState(payload);
            else await refresh();
        }
    };

    const openNotification = async notification => {
        await request('/notifications/' + notification.id + '/read');
        setOpen(false);
        if (notification.url) router.visit(notification.url);
    };

    const unread = Number(state.unread_count || 0);
    const badge = unread > 9 ? '9+' : unread;

    return <div ref={root} className="relative">
        <button type="button" onClick={() => { setOpen(value => !value); refresh(); }} className="relative rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-600 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="Compliance alerts" aria-expanded={open}>
            <Icon icon="solar:bell-linear" width="22" height="22" />
            {unread > 0 && <span className="absolute -right-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-5 text-white">{badge}</span>}
        </button>
        {open && <div className="absolute right-0 z-50 mt-2 w-[22rem] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
            <div className="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <p className="text-sm font-bold text-gray-900 dark:text-white">Notifications</p>
                <p className="text-xs text-gray-500">{unread ? unread + ' compliance alert' + (unread === 1 ? '' : 's') : 'No new compliance alerts.'}</p>
            </div>
            <div className="max-h-[26rem] overflow-y-auto">
                {state.notifications?.length ? state.notifications.map(notification => <button type="button" key={notification.id} onClick={() => openNotification(notification)} className="flex w-full gap-3 border-b border-gray-100 px-4 py-3 text-left transition hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/70">
                    <Icon icon={icon(notification.severity)} width="19" height="19" className={'mt-0.5 shrink-0 ' + tone(notification.severity)} />
                    <span className="min-w-0 flex-1">
                        <span className="flex items-start justify-between gap-2"><strong className="text-sm text-gray-800 dark:text-gray-100">{notification.title}</strong><i className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-red-500" /></span>
                        <span className="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400">{notification.message}</span>
                        <span className="mt-1 block text-[11px] text-gray-400">{relativeTime(notification.created_at)}</span>
                    </span>
                </button>) : <div className="px-4 py-8 text-center text-sm text-gray-500">No new compliance alerts.</div>}
            </div>
            <div className="border-t border-gray-100 p-2 dark:border-gray-800">
                <button type="button" onClick={() => request('/notifications/clear', 'POST')} className="block w-full rounded-lg px-3 py-2 text-center text-sm font-semibold text-green-700 hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-950/40">Clear Notifications</button>
            </div>
        </div>}
    </div>;
}
