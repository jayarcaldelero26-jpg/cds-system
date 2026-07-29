import React, { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';

export default function SuccessAlert() {
    const { flash } = usePage().props;
    const [visible, setVisible] = useState(false);
    const [message, setMessage] = useState('');

    useEffect(() => {
        if (flash?.success) {
            setMessage(flash.success);
            setVisible(true);
            const timer = setTimeout(() => {
                setVisible(false);
            }, 4000); // 4 seconds mo-close ra kalit
            return () => clearTimeout(timer);
        }
    }, [flash]);

    if (!visible || !message) return null;

    return (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-green-50 p-4 border border-green-200 text-green-800 dark:bg-green-950/50 dark:border-green-800 dark:text-green-300 shadow-sm transition-all duration-300">
            <div className="flex items-center gap-3">
                <svg className="h-5 w-5 text-green-600 dark:text-green-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                </svg>
                <span className="text-sm font-medium">{message}</span>
            </div>
            <button
                onClick={() => setVisible(false)}
                className="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-200 font-bold text-lg leading-none px-1"
            >
                ×
            </button>
        </div>
    );
}
