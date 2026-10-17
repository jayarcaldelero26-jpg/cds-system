import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import SuccessDialog from '@/Components/SuccessDialog';

// Inertia v3 flash objects are operation-bound and are deliberately omitted
// from browser history state. Weak keys keep replay tracking bounded to payloads
// still alive in the current page, with consumption scoped to the account.
const consumedFlashAccounts = new WeakMap();

function wasConsumedForAccount(flash, accountKey) {
    let accounts = consumedFlashAccounts.get(flash);
    if (!accounts) {
        accounts = new Set();
        consumedFlashAccounts.set(flash, accounts);
    }
    if (accounts.has(accountKey)) return true;
    accounts.add(accountKey);
    return false;
}

function flashEvent(nextFlash = {}) {
    if (nextFlash.registration_success) {
        return {
            title: 'Registration Request Submitted',
            message: typeof nextFlash.registration_success === 'string'
                ? nextFlash.registration_success
                : 'Your account has been created successfully and is awaiting administrator approval. You may sign in once your account has been activated.',
        };
    }

    return nextFlash.success && !nextFlash.status
        ? { title: 'Success', message: nextFlash.success }
        : null;
}

export default function FlashSuccessDialog() {
    const { flash = {}, props = {} } = usePage();
    const accountKey = String(props.auth?.user?.id ?? 'guest');
    const [event, setEvent] = useState(null);
    const [eventKey, setEventKey] = useState(0);

    useEffect(() => {
        if (!flash || typeof flash !== 'object') return;
        const nextEvent = flashEvent(flash);
        if (!nextEvent || wasConsumedForAccount(flash, accountKey)) return;

        setEvent(nextEvent);
        setEventKey(key => key + 1);
    }, [flash, accountKey]);

    const close = useCallback(() => setEvent(null), []);

    return <SuccessDialog key={eventKey} open={Boolean(event)} title={event?.title || 'Success'} message={event?.message || ''} onClose={close} />;
}
