import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const SHOW_DELAY = 200;
const MINIMUM_VISIBLE = 240;

export default function GlobalLoadingOverlay() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        let timer;
        let shownAt = 0;

        const start = router.on('start', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                shownAt = Date.now();
                setVisible(true);
            }, SHOW_DELAY);
        });

        const finish = router.on('finish', () => {
            window.clearTimeout(timer);
            const remaining = visible ? Math.max(0, MINIMUM_VISIBLE - (Date.now() - shownAt)) : 0;
            window.setTimeout(() => setVisible(false), remaining);
        });

        return () => {
            window.clearTimeout(timer);
            start();
            finish();
        };
    }, [visible]);

    if (!visible) return null;

    return <div className="global-loading-overlay" role="status" aria-live="polite" aria-label="Loading">
        <div className="global-loading-card">
            <svg className="global-loading-gears" viewBox="0 0 72 64" role="img" aria-hidden="true">
                <g className="global-loading-gear global-loading-gear-large">
                    <circle cx="26" cy="25" r="14" />
                    <g className="global-loading-gear-teeth">{Array.from({ length: 10 }, (_, index) => <rect key={index} x="24" y="3" width="4" height="7" rx="1" transform={`rotate(${index * 36} 26 25)`} />)}</g>
                    <circle className="global-loading-gear-hole" cx="26" cy="25" r="5" />
                </g>
                <g className="global-loading-gear global-loading-gear-small">
                    <circle cx="47" cy="42" r="9" />
                    <g className="global-loading-gear-teeth">{Array.from({ length: 8 }, (_, index) => <rect key={index} x="45" y="28" width="4" height="5" rx="1" transform={`rotate(${index * 45} 47 42)`} />)}</g>
                    <circle className="global-loading-gear-hole" cx="47" cy="42" r="3.5" />
                </g>
            </svg>
            <span className="global-loading-label">Loading...</span>
        </div>
    </div>;
}
