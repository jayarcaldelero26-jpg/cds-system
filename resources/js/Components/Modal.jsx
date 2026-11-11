import { useRef } from 'react';
import ModalPortal from '@/Components/ModalPortal';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';
import { MODAL_MOTION_PRESETS } from '@/Components/ModalMotion/presets.mjs';

/** Shared modal frame for custom and self-managed dialog renderers. */
export function ModalFrame({
    open, present, phase, onTransitionEnd, children, layerContent = null,
    title, onClose, closeOnBackdrop = false, panelRef, layerRef,
    as: Panel = 'section', role = 'dialog', labelledBy, describedBy,
    ariaLabel, panelClassName = '', className = '',
    backdropClassName = 'bg-gray-950/60 backdrop-blur-xs', wrapperClassName = '',
    panelStyle, backdropStyle, panelProps = {}, backdropProps = {},
    zIndex = 'z-50', placement = 'center', tabIndex = -1,
    motion = MODAL_MOTION_PRESETS.content,
}) {
    if (!present) return null;
    const alignment = placement === 'right'
        ? 'items-stretch justify-end'
        : placement === 'top'
            ? 'items-start justify-center'
            : 'items-center justify-center';
    const handleBackdropMouseDown = event => {
        backdropProps.onMouseDown?.(event);
        if (event.defaultPrevented) return;
        if (open && closeOnBackdrop && event.target === event.currentTarget) onClose?.();
    };
    return <ModalPortal>
        <div
            ref={layerRef}
            data-cds-modal-layer
            data-motion-state={phase}
            data-cds-modal-presentation={placement}
            data-cds-modal-motion={motion}
            className={`cds-modal-motion-layer fixed inset-0 ${zIndex} flex ${alignment} overflow-y-auto ${wrapperClassName}`.trim()}
            style={backdropStyle}
        >
            <div
                {...backdropProps}
                data-motion-state={phase}
                data-cds-modal-motion={motion}
                className={`cds-modal-motion-backdrop absolute inset-0 ${backdropClassName}`.trim()}
                role="presentation"
                onMouseDown={handleBackdropMouseDown}
            />
            <Panel
                {...panelProps}
                ref={panelRef}
                onTransitionEnd={event => {
                    panelProps.onTransitionEnd?.(event);
                    onTransitionEnd?.(event);
                }}
                tabIndex={panelProps.tabIndex ?? tabIndex}
                role={role}
                aria-modal="true"
                aria-label={ariaLabel || (!labelledBy ? title : undefined)}
                aria-labelledby={labelledBy}
                aria-describedby={describedBy}
                data-motion-state={phase}
                data-cds-modal-motion={motion}
                className={`cds-modal-motion-panel relative z-10 ${panelClassName} ${className}`.trim()}
                style={panelStyle}
            >
                {children}
            </Panel>
            {layerContent}
        </div>
    </ModalPortal>;
}

/** Shared dialog API. Workflow state remains in the calling page/component. */
export default function Modal(props) {
    const {
        open, onClose, onExited, closeOnEscape = true,
        suspendKeyboardHandling = false, motion = MODAL_MOTION_PRESETS.content,
        initialFocusSelector = null, returnFocusTarget = null,
        panelRef: suppliedPanelRef, focusPanelRef: suppliedFocusPanelRef,
        layerRef: suppliedLayerRef,
    } = props;
    const internalPanelRef = useRef(null);
    const internalLayerRef = useRef(null);
    const panelRef = suppliedPanelRef || internalPanelRef;
    const layerRef = suppliedLayerRef || internalLayerRef;
    const focusPanelRef = suppliedFocusPanelRef || panelRef;
    const { present, phase, onTransitionEnd } = useModalMotion(open, {
        onClose, onExited, panelRef: focusPanelRef, layerRef,
        initialFocusSelector, returnFocusTarget, closeOnEscape,
        suspendKeyboardHandling, motion,
    });
    return <ModalFrame {...props} present={present} phase={phase} onTransitionEnd={onTransitionEnd} panelRef={panelRef} layerRef={layerRef} />;
}
