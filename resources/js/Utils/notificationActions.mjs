const pendingReads = new Set();

export function shouldFollowNotificationDestination(page) {
    const props = page?.props ?? {};
    return props.trackingContext?.notification_action !== 'stale_incoming'
        && props.flash?.status !== 'This report no longer needs your action.';
}

/** Mark one owned notification read through Inertia's same-origin request client. */
export function markNotificationRead(router, notificationId, callbacks = {}) {
    const id = String(notificationId ?? '');
    if (!id || pendingReads.has(id)) return false;

    pendingReads.add(id);
    callbacks.onStart?.();

    try {
        router.patch(`/notifications/${encodeURIComponent(id)}/read`, {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: page => callbacks.onSuccess?.(page),
            onError: errors => callbacks.onFailure?.({ kind: 'response', errors }),
            onHttpException: response => {
                callbacks.onFailure?.({
                    kind: response?.status === 419 ? 'expired' : 'http',
                    status: response?.status ?? null,
                });
                return false;
            },
            onNetworkError: error => {
                callbacks.onFailure?.({ kind: 'network', error });
                return false;
            },
            onFinish: () => {
                pendingReads.delete(id);
                callbacks.onFinish?.();
            },
        });
    } catch (error) {
        pendingReads.delete(id);
        callbacks.onFailure?.({ kind: 'network', error });
        callbacks.onFinish?.();
    }

    return true;
}
