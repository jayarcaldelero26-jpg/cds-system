import React, { useCallback, useState } from 'react';

export function Head() {
    return null;
}

export function Link({ href = '#', children, ...props }) {
    return <a href={href} {...props}>{children}</a>;
}

export const router = {
    get(...args) { window.__calendarFixture.routerCalls.push(['get', ...args]); },
    put(...args) { window.__calendarFixture.mutations.push(['put', ...args]); },
    post(...args) { window.__calendarFixture.mutations.push(['post', ...args]); },
    delete(...args) { window.__calendarFixture.mutations.push(['delete', ...args]); },
};

export function usePage() {
    return { props: { auth: { canManageComplianceAlerts: false } } };
}

export function useRemember(value) {
    return useState(value);
}

export function useForm(initial = {}) {
    const [data, setDataState] = useState(() => ({ ...initial }));
    const [errors, setErrors] = useState({});
    const setData = useCallback((keyOrData, value) => {
        setDataState(current => typeof keyOrData === 'string'
            ? { ...current, [keyOrData]: value }
            : { ...keyOrData });
    }, []);
    const reset = useCallback(() => setDataState({ ...initial }), [initial]);
    const clearErrors = useCallback(() => setErrors({}), []);
    const setError = useCallback((key, message) => setErrors(current => ({ ...current, [key]: message })), []);
    const mockMutation = useCallback((...args) => {
        window.__calendarFixture.mutations.push(['form', ...args]);
    }, []);

    return {
        data,
        errors,
        processing: false,
        setData,
        reset,
        clearErrors,
        setError,
        post: mockMutation,
        put: mockMutation,
        delete: mockMutation,
    };
}
