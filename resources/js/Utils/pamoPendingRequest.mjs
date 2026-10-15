/** Start one existing Inertia mutation with local, request-scoped presentation callbacks. */
export function startPamoPendingRequest(state, { intent, setIntent, send, onSuccess, onError, onCancel, onHttpException, onNetworkError }) {
    if (state.activeId !== null) return false;

    const requestId = ++state.sequence;
    state.activeId = requestId;
    state.intent = intent;
    setIntent(intent);
    let outcome = null;

    const isCurrent = () => state.activeId === requestId;
    const settle = (nextOutcome, callback, value) => {
        if (!isCurrent() || outcome !== null) return;
        outcome = nextOutcome;
        state.intent = null;
        setIntent(null);
        callback?.(value);
    };

    const callbacks = {
        onStart: () => {
            if (isCurrent() && outcome === null) {
                state.intent = intent;
                setIntent(intent);
            }
        },
        onSuccess: page => settle('success', onSuccess, page),
        onError: errors => settle('error', onError, errors),
        onCancel: () => settle('cancelled', onCancel),
        onHttpException: response => settle('http-error', onHttpException, response),
        onNetworkError: error => settle('network-error', onNetworkError, error),
        onFinish: () => {
            if (!isCurrent()) return;
            state.activeId = null;
            state.intent = null;
            setIntent(null);
        },
    };

    try {
        send(callbacks);
    } catch (error) {
        if (isCurrent()) {
            state.activeId = null;
            state.intent = null;
            setIntent(null);
        }
        throw error;
    }

    return true;
}

/** Invalidate callbacks from an old PA/workspace or an unmounted presentation host. */
export function invalidatePamoPendingRequest(state) {
    state.sequence += 1;
    state.activeId = null;
    state.intent = null;
}
