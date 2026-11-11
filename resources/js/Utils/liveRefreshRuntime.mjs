export const LIVE_REFRESH_FALLBACK_INTERVAL_MS = 10_000;

export function createLiveRefreshRuntime({
    onRefresh,
    isVisible = true,
    intervalMs = LIVE_REFRESH_FALLBACK_INTERVAL_MS,
    setIntervalImpl = globalThis.setInterval,
    clearIntervalImpl = globalThis.clearInterval,
}) {
    let connected = false;
    let visible = Boolean(isVisible);
    let disposed = false;
    let timer = null;

    const stopPolling = () => {
        if (timer === null) return;
        clearIntervalImpl(timer);
        timer = null;
    };

    const startPolling = () => {
        if (disposed || connected || !visible || timer !== null) return;
        timer = setIntervalImpl(() => {
            if (!disposed && !connected && visible) onRefresh('all');
        }, intervalMs);
    };

    return {
        start() {
            startPolling();
        },
        setConnectionState(state) {
            if (disposed) return;
            const nextConnected = state === 'connected';
            if (nextConnected === connected) return;
            connected = nextConnected;
            if (connected) {
                stopPolling();
                onRefresh('all');
            } else {
                startPolling();
            }
        },
        setVisible(nextVisible) {
            if (disposed) return;
            const next = Boolean(nextVisible);
            if (next === visible) return;
            visible = next;
            if (visible) {
                onRefresh('all');
                startPolling();
            } else {
                stopPolling();
            }
        },
        online() {
            if (disposed) return;
            onRefresh('all');
            startPolling();
        },
        dispose() {
            if (disposed) return;
            disposed = true;
            stopPolling();
        },
        get connected() {
            return connected;
        },
        get polling() {
            return timer !== null;
        },
    };
}

export function createCoalescedPartialReload(router, only, {
    schedule = globalThis.setTimeout,
    cancel = globalThis.clearTimeout,
    showProgress,
} = {}) {
    let scheduled = null;
    let inFlight = false;
    let pending = false;
    let disposed = false;

    const request = () => {
        if (disposed) return;
        if (inFlight) {
            pending = true;
            return;
        }
        if (scheduled !== null) return;
        scheduled = schedule(run, 0);
    };

    const run = () => {
        scheduled = null;
        if (disposed) return;
        if (inFlight) {
            pending = true;
            return;
        }

        inFlight = true;
        router.reload({
            only,
            preserveState: true,
            preserveScroll: true,
            ...(showProgress === undefined ? {} : { showProgress }),
            onFinish: () => {
                inFlight = false;
                if (pending && !disposed) {
                    pending = false;
                    request();
                }
            },
        });
    };

    return {
        request,
        dispose() {
            disposed = true;
            pending = false;
            if (scheduled !== null) cancel(scheduled);
            scheduled = null;
        },
    };
}
