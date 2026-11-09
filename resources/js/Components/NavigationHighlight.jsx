import { useLayoutEffect, useRef } from 'react';
import { createNavigationHighlight } from '@/Utils/shellMotion.mjs';

export default function NavigationHighlight({ navigationRef, currentUrl, expandedGroups, items, pendingUrl = null }) {
    const pillRef = useRef(null);
    const controllerRef = useRef(null);

    useLayoutEffect(() => {
        let disposed = false;
        let controller = null;
        let nav = null;
        let view = null;
        let observer = null;
        let scheduledCancel = null;

        const remeasure = () => controller?.refresh(false);
        const attach = () => {
            scheduledCancel = null;
            if (disposed) return;

            nav = navigationRef.current;
            const pill = pillRef.current;
            if (!nav || !pill) {
                view = nav?.ownerDocument.defaultView || globalThis.window;
                if (typeof view?.requestAnimationFrame === 'function') {
                    const frame = view.requestAnimationFrame(attach);
                    scheduledCancel = () => view.cancelAnimationFrame(frame);
                } else if (typeof view?.setTimeout === 'function') {
                    const timeout = view.setTimeout(attach, 0);
                    scheduledCancel = () => view.clearTimeout(timeout);
                }
                return;
            }

            view = nav.ownerDocument.defaultView;
            controller = createNavigationHighlight(nav, pill, view);
            controllerRef.current = controller;
            controller.refresh(false, pendingUrl);
            view.addEventListener('resize', remeasure, { passive: true });
            observer = typeof view.ResizeObserver === 'function' ? new view.ResizeObserver(remeasure) : null;
            observer?.observe(nav);
            nav.ownerDocument.fonts?.ready.then(remeasure, () => {});
        };

        if (typeof globalThis.queueMicrotask === 'function') globalThis.queueMicrotask(attach);
        else Promise.resolve().then(attach);

        return () => {
            disposed = true;
            scheduledCancel?.();
            observer?.disconnect();
            view?.removeEventListener('resize', remeasure);
            controller?.dispose();
            if (controllerRef.current === controller) controllerRef.current = null;
        };
    }, [navigationRef]);

    useLayoutEffect(() => {
        controllerRef.current?.refresh(true, pendingUrl);
    }, [currentUrl, expandedGroups, items, pendingUrl]);

    return <span ref={pillRef} hidden aria-hidden="true" className="cds-navigation-pill" />;
}
