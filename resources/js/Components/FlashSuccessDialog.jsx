import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import SuccessDialog from '@/Components/SuccessDialog';

export default function FlashSuccessDialog() {
    const { flash = {} } = usePage().props;
    const [message, setMessage] = useState(null);

    useEffect(() => {
        // Legacy status-based pages retain their existing single feedback listener.
        // This shared listener owns only the canonical human-readable success flash.
        setMessage(flash.success && !flash.status ? flash.success : null);
    }, [flash]);

    const close = useCallback(() => setMessage(null), []);

    return <SuccessDialog open={Boolean(message)} message={message} onClose={close} />;
}
