import { cloneElement, createElement, isValidElement } from 'react';
import { createPortal } from 'react-dom';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

const GAP = 8;
const DELAY = 240;

export default function Tooltip({ content, children, placement = 'top', className = '' }) {
    const triggerRef = useRef(null);
    const timerRef = useRef(null);
    const tooltipId = `tooltip-${useId().replace(/:/g, '')}`;
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState({ top: 0, left: 0, placement });

    const show = () => {
        window.clearTimeout(timerRef.current);
        timerRef.current = window.setTimeout(() => setOpen(true), DELAY);
    };
    const hide = () => {
        window.clearTimeout(timerRef.current);
        setOpen(false);
    };

    useLayoutEffect(() => {
        if (!open || !triggerRef.current) return undefined;
        const update = () => {
            const rect = triggerRef.current.getBoundingClientRect();
            const width = 260;
            const height = 36;
            const spaces = {
                top: rect.top,
                bottom: window.innerHeight - rect.bottom,
                left: rect.left,
                right: window.innerWidth - rect.right,
            };
            let resolved = placement;
            if (resolved === 'top' && spaces.top < height + GAP) resolved = spaces.bottom >= height + GAP ? 'bottom' : 'right';
            if (resolved === 'bottom' && spaces.bottom < height + GAP) resolved = spaces.top >= height + GAP ? 'top' : 'left';
            if (resolved === 'left' && spaces.left < width + GAP) resolved = spaces.right >= width + GAP ? 'right' : 'top';
            if (resolved === 'right' && spaces.right < width + GAP) resolved = spaces.left >= width + GAP ? 'left' : 'top';
            const centerX = Math.min(Math.max(rect.left + rect.width / 2, width / 2 + 8), window.innerWidth - width / 2 - 8);
            const centerY = Math.min(Math.max(rect.top + rect.height / 2, height / 2 + 8), window.innerHeight - height / 2 - 8);
            const coords = {
                top: { top: rect.top - GAP, left: centerX },
                bottom: { top: rect.bottom + GAP, left: centerX },
                left: { top: centerY, left: rect.left - GAP },
                right: { top: centerY, left: rect.right + GAP },
            }[resolved];
            setPosition({ ...coords, placement: resolved });
        };
        update();
        window.addEventListener('scroll', update, true);
        window.addEventListener('resize', update);
        return () => { window.removeEventListener('scroll', update, true); window.removeEventListener('resize', update); };
    }, [open, placement]);

    useEffect(() => () => window.clearTimeout(timerRef.current), []);

    if (!content) return children;
    const trigger = isValidElement(children) ? children : createElement('span', null, children);
    const enhanced = isValidElement(trigger) ? cloneElement(trigger, open ? { 'aria-describedby': tooltipId } : {}) : trigger;

    return <span ref={triggerRef} className={`tooltip-trigger ${className}`} onMouseEnter={show} onMouseLeave={hide} onFocus={show} onBlur={hide}>
        {enhanced}
        {open && createPortal(<div id={tooltipId} role="tooltip" className={`shared-tooltip shared-tooltip-${position.placement}`} style={{ top: position.top, left: position.left }}>{content}<span className="shared-tooltip-arrow" aria-hidden="true" /></div>, document.body)}
    </span>;
}
