import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import SuccessDialog from '@/Components/SuccessDialog';

export default function FlashSuccessDialog() {
    const { flash = {} } = usePage().props;
    const initialMessage = flash.success && !flash.status ? flash.success : null;
    const [message, setMessage] = useState(initialMessage);
    const eventSequence = useRef(initialMessage ? 1 : 0);
    const [eventKey, setEventKey] = useState(eventSequence.current);

    useEffect(() => {
        // A navigation is the success-event identity. The message text and URL may
        // legitimately be identical for several consecutive mutations.
        return router.on('success', event => {
            const nextFlash = event.detail.page.props.flash || {};
            const nextMessage = nextFlash.success && !nextFlash.status ? nextFlash.success : null;

            if (!nextMessage) return;

            eventSequence.current += 1;
            setEventKey(eventSequence.current);
            setMessage(nextMessage);
        });
    }, []);

    const close = useCallback(() => setMessage(null), []);

    return <SuccessDialog key={eventKey} open={Boolean(message)} message={message} onClose={close} />;
}
