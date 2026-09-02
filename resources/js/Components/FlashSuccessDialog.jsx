import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import SuccessDialog from '@/Components/SuccessDialog';

export default function FlashSuccessDialog() {
    const { flash = {} } = usePage().props;
    const flashEvent = (nextFlash = {}) => {
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
    };
    const initialEvent = flashEvent(flash);
    const [event, setEvent] = useState(initialEvent);
    const eventSequence = useRef(initialEvent ? 1 : 0);
    const [eventKey, setEventKey] = useState(eventSequence.current);

    useEffect(() => {
        // A navigation is the success-event identity. The message text and URL may
        // legitimately be identical for several consecutive mutations.
        return router.on('success', event => {
            const nextFlash = event.detail.page.props.flash || {};
            const nextEvent = flashEvent(nextFlash);

            if (!nextEvent) return;

            eventSequence.current += 1;
            setEventKey(eventSequence.current);
            setEvent(nextEvent);
        });
    }, []);

    const close = useCallback(() => setEvent(null), []);

    return <SuccessDialog key={eventKey} open={Boolean(event)} title={event?.title || 'Success'} message={event?.message || ''} onClose={close} />;
}
