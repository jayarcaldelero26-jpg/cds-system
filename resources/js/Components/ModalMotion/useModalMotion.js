import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { registerModalLayer } from './modalLayer.mjs';
import { focusInitialModalTarget } from './initialFocus.mjs';
import { restoreModalFocusTarget } from './focusReturn.mjs';

export const MODAL_MOTION_DURATION = 240;

export function useModalMotion(open, {
    onClose,
    onExited,
    panelRef,
    layerRef = panelRef,
    initialFocusSelector = null,
    returnFocusTarget = null,
    closeOnEscape = true,
    suspendKeyboardHandling = false,
    motion = 'soft',
} = {}) {
    const duration = motion === 'none' ? 0 : MODAL_MOTION_DURATION;
    const [present, setPresent] = useState(Boolean(open));
    const [phase, setPhase] = useState(open ? 'entering' : 'closed');
    const presentRef = useRef(Boolean(open));
    const phaseRef = useRef(phase);
    const openRef = useRef(Boolean(open));
    const generationRef = useRef(0);
    const onCloseRef = useRef(onClose);
    const onExitedRef = useRef(onExited);
    const returnFocusRef = useRef(null);
    const focusStartedRef = useRef(false);
    const idRef = useRef(Symbol('cds-modal-layer'));

    onCloseRef.current = onClose;
    onExitedRef.current = onExited;
    openRef.current = Boolean(open);

    const setMotionPhase = next => {
        phaseRef.current = next;
        setPhase(next);
    };
    const finishExit = token => {
        if (token !== generationRef.current || openRef.current || !presentRef.current) return;
        presentRef.current = false;
        setPresent(false);
        setMotionPhase('closed');
        onExitedRef.current?.();
    };

    useEffect(() => {
        const token = ++generationRef.current;
        let frame = null;
        let timer = null;
        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

        if (open) {
            presentRef.current = true;
            setPresent(true);
            if (reducedMotion || duration === 0) {
                setMotionPhase('open');
                return undefined;
            }
            setMotionPhase('entering');
            frame = window.requestAnimationFrame(() => {
                if (token === generationRef.current && openRef.current) setMotionPhase('open');
            });
        } else if (presentRef.current) {
            if (reducedMotion || duration === 0) {
                finishExit(token);
            } else {
                setMotionPhase('closing');
                timer = window.setTimeout(() => finishExit(token), duration + 80);
            }
        } else {
            setMotionPhase('closed');
        }

        return () => {
            if (frame !== null) window.cancelAnimationFrame(frame);
            if (timer !== null) window.clearTimeout(timer);
        };
    }, [open, duration]);

    useLayoutEffect(() => {
        if (!present) {
            focusStartedRef.current = false;
            return undefined;
        }
        if (!open || focusStartedRef.current) return undefined;
        const panel = panelRef?.current;
        if (!panel || (panel.getClientRects && panel.getClientRects().length === 0)) return undefined;
        focusStartedRef.current = true;
        if (!returnFocusRef.current) {
            returnFocusRef.current = returnFocusTarget && document.contains(returnFocusTarget)
                ? returnFocusTarget
                : document.activeElement;
        }
        focusInitialModalTarget(panel, initialFocusSelector);
        return undefined;
    }, [present, open, panelRef, initialFocusSelector, returnFocusTarget]);

    useEffect(() => {
        if (present && panelRef?.current) {
            const layer = layerRef?.current || panelRef.current;
            const zIndex = Number.parseInt(window.getComputedStyle(layer).zIndex, 10) || 0;
            return registerModalLayer({
                id: idRef.current,
                panel: panelRef.current,
                layer,
                zIndex,
                closeOnEscape,
                suspendKeyboardHandling,
                onEscape: () => onCloseRef.current?.(),
            });
        }
        const previous = returnFocusRef.current;
        returnFocusRef.current = null;
        restoreModalFocusTarget(previous, document);
        return undefined;
    }, [present, panelRef, layerRef, closeOnEscape, suspendKeyboardHandling]);

    useEffect(() => () => {
        const previous = returnFocusRef.current;
        returnFocusRef.current = null;
        restoreModalFocusTarget(previous, document, callback => callback());
    }, []);

    const onTransitionEnd = event => {
        if (event.target !== panelRef?.current || event.propertyName !== 'transform') return;
        if (phaseRef.current === 'closing') finishExit(generationRef.current);
    };

    const displayedPhase = open && (phase === 'closed' || phase === 'closing') ? 'entering' : phase;
    return { present: present || Boolean(open), phase: displayedPhase, onTransitionEnd };
}
