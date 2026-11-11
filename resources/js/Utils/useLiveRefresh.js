import { useEffect } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { createLiveRefreshRuntime } from './liveRefreshRuntime.mjs';

const REFRESH_SCOPES = new Set(['notifications', 'tracking', 'archives']);

function dispatchRefresh(scope, token = null) {
    window.dispatchEvent(new CustomEvent('cds:live-refresh', { detail: { scope, token } }));
    if (scope === 'all' || scope === 'notifications') {
        window.dispatchEvent(new Event('cds:notifications-updated'));
    }
}

export function useLiveRefresh(userId) {
    useEffect(() => {
        if (!userId) return undefined;

        globalThis.Pusher = Pusher;
        const runtime = createLiveRefreshRuntime({
            onRefresh: scope => dispatchRefresh(scope),
            isVisible: document.visibilityState === 'visible',
        });
        runtime.start();

        const handleVisibility = () => runtime.setVisible(document.visibilityState === 'visible');
        const handleOnline = () => runtime.online();
        document.addEventListener('visibilitychange', handleVisibility);
        window.addEventListener('online', handleOnline);

        let echo = null;
        let connection = null;
        let handleStateChange = null;
        let channel = null;
        let socketConnected = false;
        let channelSubscribed = false;
        const seenTokens = new Set();

        const updateConnectionHealth = () => {
            runtime.setConnectionState(socketConnected && channelSubscribed ? 'connected' : 'unavailable');
        };

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const appKey = import.meta.env.VITE_REVERB_APP_KEY;
            const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
            const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
            const secure = import.meta.env.VITE_REVERB_SCHEME === 'https';

            if (appKey && host) {
                echo = new Echo({
                    broadcaster: 'reverb',
                    key: appKey,
                    wsHost: host,
                    wsPort: port,
                    wssPort: port,
                    forceTLS: secure,
                    enabledTransports: secure ? ['wss'] : ['ws', 'wss'],
                    authEndpoint: '/broadcasting/auth',
                    auth: { headers: csrf ? { 'X-CSRF-TOKEN': csrf } : {} },
                    disableStats: true,
                });

                connection = echo.connector?.pusher?.connection || null;
                handleStateChange = states => {
                    socketConnected = states?.current === 'connected';
                    if (!socketConnected) channelSubscribed = false;
                    updateConnectionHealth();
                };
                connection?.bind('state_change', handleStateChange);
                socketConnected = connection?.state === 'connected';
                updateConnectionHealth();

                channel = echo.private(`users.${userId}.live-refresh`);
                channel.subscribed(() => {
                    channelSubscribed = true;
                    updateConnectionHealth();
                });
                channel.error(() => {
                    channelSubscribed = false;
                    updateConnectionHealth();
                });
                channel.listen('.cds.live-refresh', message => {
                    const scope = message?.scope;
                    const token = typeof message?.token === 'string' ? message.token : '';
                    if (!REFRESH_SCOPES.has(scope) || !token || seenTokens.has(token)) return;
                    seenTokens.add(token);
                    if (seenTokens.size > 120) seenTokens.delete(seenTokens.values().next().value);
                    dispatchRefresh(scope, token);
                });
            }
        } catch {
            // The ten-second scoped fallback remains active when the socket cannot start.
        }

        return () => {
            document.removeEventListener('visibilitychange', handleVisibility);
            window.removeEventListener('online', handleOnline);
            runtime.dispose();
            if (connection && handleStateChange) connection.unbind('state_change', handleStateChange);
            if (echo) {
                echo.leave(`users.${userId}.live-refresh`);
                echo.disconnect();
            }
        };
    }, [userId]);
}
