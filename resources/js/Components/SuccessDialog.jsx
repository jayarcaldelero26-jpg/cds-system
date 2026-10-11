import { useRef } from 'react';
import Button from '@/Components/Button';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';

export default function SuccessDialog({ open, message, title = 'Success', onClose }) {
    const buttonRef = useRef(null);
    const dialogRef = useRef(null);
    const { present, phase, onTransitionEnd } = useModalMotion(open, {
        onClose,
        panelRef: dialogRef,
        layerRef: dialogRef,
        initialFocusSelector: '#success-dialog-ok',
    });
    if (!present) return null;

    return <div className="cds-modal-motion-backdrop fixed inset-0 z-[100] flex items-center justify-center bg-gray-950/45 p-4" data-motion-state={phase} role="presentation" onMouseDown={event => open && event.target === event.currentTarget && onClose?.()}>
        <style>{`
            @keyframes success-ring-clockwise {
                0% { opacity: 0; transform: rotate(0) scale(.9); }
                7% { opacity: .82; transform: rotate(18deg) scale(1); }
                62% { opacity: .82; transform: rotate(360deg) scale(1); }
                75%, 100% { opacity: 0; transform: rotate(390deg) scale(.82); }
            }
            @keyframes success-ring-counterclockwise {
                0% { opacity: 0; transform: rotate(0) scale(.9); }
                7% { opacity: .62; transform: rotate(-16deg) scale(1); }
                62% { opacity: .62; transform: rotate(-340deg) scale(1); }
                75%, 100% { opacity: 0; transform: rotate(-370deg) scale(.82); }
            }
            @keyframes success-final-check-scale {
                0% { opacity: 0; transform: scale(.82); }
                8% { opacity: 1; transform: scale(.82); }
                24% { opacity: 1; transform: scale(.94); }
                62% { opacity: 1; transform: scale(1); }
                74% { opacity: 1; transform: scale(.82); }
                83% { opacity: 1; transform: scale(1.1); }
                92% { opacity: 1; transform: scale(.96); }
                100% { opacity: 1; transform: scale(1); }
            }
            @keyframes success-final-stroke {
                0% { stroke-dashoffset: 120; }
                62%, 100% { stroke-dashoffset: 0; }
            }
            .success-ring { transform-box: view-box; transform-origin: center; }
            .success-final-group { transform-box: fill-box; transform-origin: center; }
            .success-ring-outer { stroke-dasharray: 118 22 72 40 50 50; animation: success-ring-clockwise 1700ms 100ms cubic-bezier(.4, 0, .2, 1) both; }
            .success-ring-inner { stroke-dasharray: 52 14 94 22 43 34; animation: success-ring-counterclockwise 1700ms 100ms cubic-bezier(.4, 0, .2, 1) both; }
            .success-final-group { animation: success-final-check-scale 1700ms 100ms ease-in-out both; transform-origin: 70px 70px; }
            .success-final-stroke { stroke-dasharray: 120; stroke-dashoffset: 120; animation: success-final-stroke 1700ms 100ms cubic-bezier(.22, .65, .3, 1) both; }
            @media (prefers-reduced-motion: reduce) {
                .success-ring,
                .success-final-group,
                .success-final-stroke { animation: none !important; }
                .success-ring { display: none; }
                .success-final-group { opacity: 1; transform: scale(1); }
                .success-final-stroke { stroke-dashoffset: 0; }
            }
        `}</style>
        <div ref={dialogRef} onTransitionEnd={onTransitionEnd} data-motion-state={phase} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="success-dialog-title" aria-describedby="success-dialog-message" className="cds-modal-motion-panel w-full max-w-[390px] rounded-lg bg-white px-7 py-6 text-center shadow-xl dark:bg-gray-900">
            <div className="mx-auto flex h-36 w-36 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/40">
                <svg className="h-[140px] w-[140px] overflow-visible text-green-600 dark:text-green-400" viewBox="0 0 140 140" fill="none" aria-hidden="true">
                    <circle className="success-ring success-ring-outer" cx="70" cy="70" r="57" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round"/>
                    <circle className="success-ring success-ring-inner" cx="70" cy="70" r="48" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round"/>
                    <g className="success-final-group">
                        <path className="success-final-stroke" d="M24 70L54 99L116 38" stroke="currentColor" strokeWidth="10" strokeLinecap="round" strokeLinejoin="round"/>
                    </g>
                </svg>
            </div>
            <h2 id="success-dialog-title" className="mt-3.5 text-2xl font-medium leading-tight text-gray-800 dark:text-gray-100">{title}</h2>
            <p id="success-dialog-message" className="mx-auto mt-2 max-w-xs break-words text-[15px] font-normal leading-6 text-gray-500 dark:text-gray-400">{message}</p>
            <Button ref={buttonRef} id="success-dialog-ok" type="button" onClick={onClose} className="mt-5 min-w-20 rounded-md px-5 py-2 text-[13px] font-medium leading-4">OK</Button>
        </div>
    </div>;
}
