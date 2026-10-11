import { useRef } from 'react';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';
import SaveProgressIndicator from '@/Components/Attachments/SaveProgressIndicator';
import Button from '@/Components/Button';
import useModalFileDrop from '@/Components/Attachments/useModalFileDrop';
import CrudModalCardContext from './CrudModalCardContext';
import { useModalMotion } from '@/Components/ModalMotion/useModalMotion';
import { resolveCrudFormInitialFocusSelector } from '@/Components/ModalMotion/initialFocus.mjs';

export default function CrudFormModal({ open, mode = 'create', icon, title, subtitle, onClose, onExited, returnFocusTarget = null, onSubmit, processing = false, progress = null, errors = {}, summaryErrors = null, systemNotice = null, children, preview, canDelete = false, onDelete, canSave = true, backLabel, saveLabel, saveVariant = 'primary', deleteLabel = 'Delete Record', maxWidth = 'max-w-7xl', initialFocusSelector = null, closeOnOverlayClick = false, suspendKeyboardHandling = false, showHeaderClose = true, motion = 'soft' }) {
    const panelRef = useRef(null);
    const processingRef = useRef(processing);
    const overlayRef = useRef(null);
    const resolvedInitialFocusSelector = resolveCrudFormInitialFocusSelector(initialFocusSelector);
    processingRef.current = processing;
    const dropMessage = useModalFileDrop(panelRef, open);
    const { present, phase, onTransitionEnd } = useModalMotion(open, {
        onClose: () => { if (!processingRef.current) onClose?.(); },
        onExited,
        panelRef,
        layerRef: overlayRef,
        initialFocusSelector: resolvedInitialFocusSelector,
        returnFocusTarget,
        suspendKeyboardHandling,
        motion,
    });
    if (!present) return null;
    const displayedErrors = summaryErrors ?? errors;
    const resolvedSaveLabel = saveLabel || (mode === 'edit' ? 'Save Changes' : 'Save Record');
    const displayPreview = Boolean(preview);
    return <div ref={overlayRef} data-cds-modal-layer data-motion-state={phase} className={`fixed inset-0 z-50 flex items-center justify-center p-4 ${motion === 'soft' ? '' : 'bg-gray-950/60 backdrop-blur-xs'}`} onClick={event => {
        const backdropTarget = event.target?.dataset?.modalBackdrop === 'true';
        if (open && closeOnOverlayClick && (event.target === event.currentTarget || backdropTarget) && !processing) onClose?.();
    }}>
        <div aria-hidden="true" data-modal-backdrop="true" data-motion-state={phase} className="cds-modal-motion-backdrop absolute inset-0 bg-gray-950/60 backdrop-blur-xs" />
        <form ref={panelRef} onTransitionEnd={onTransitionEnd} onSubmit={onSubmit} role="dialog" aria-modal="true" aria-label={title} tabIndex={-1} data-cds-modal-drop-panel data-motion-state={phase} className={`relative z-10 cds-modal-motion-panel flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900 ${maxWidth}`}>
            {dropMessage && <div className="cds-modal-drop-feedback" aria-live="polite">{dropMessage}</div>}
            <CrudModalHeader icon={icon} title={title} subtitle={subtitle} onClose={processing ? undefined : onClose} showClose={showHeaderClose} />
            <CrudModalCardContext.Provider value><div className="custom-table-scrollbar min-h-0 flex-1 overflow-y-auto p-6">
                <SaveProgressIndicator processing={processing} progress={progress} />
                {Object.keys(displayedErrors || {}).length > 0 && <div className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert"><p className="font-bold">Please correct the following:</p><ul className="mt-1 list-disc pl-5">{Object.values(displayedErrors).map((error, index) => <li key={index}>{error}</li>)}</ul></div>}
                {systemNotice && <div className="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" role="alert"><p className="font-bold">{systemNotice.title}</p><p className="mt-1">{systemNotice.message}</p></div>}
                <div className={displayPreview ? 'grid grid-cols-1 gap-6 lg:grid-cols-12' : ''}><div className={displayPreview ? 'space-y-5 lg:col-span-6' : 'space-y-5'}>{children}</div>{displayPreview && <div className="lg:col-span-6"><div className="sticky top-4">{preview}</div></div>}</div>
            </div></CrudModalCardContext.Provider>
            <CrudModalFooter left={canDelete && onDelete ? <Button type="button" size="compact" variant="danger" onClick={onDelete} disabled={processing} className="rounded-xl px-4 py-2.5 text-xs">{deleteLabel}</Button> : null}>
                <Button type="button" size="compact" variant={backLabel?.toLowerCase().includes('back') || mode === 'edit' ? 'back' : 'cancel'} onClick={onClose} disabled={processing} className="rounded-xl px-4 py-2.5 text-xs">{backLabel || (mode === 'edit' ? '← Back' : 'Cancel')}</Button>
                {canSave && <Button type="submit" size="compact" variant={saveVariant} disabled={processing} className="rounded-xl px-5 py-2.5 text-xs">{processing ? (mode === 'edit' ? 'Updating…' : 'Saving…') : resolvedSaveLabel}</Button>}
            </CrudModalFooter>
        </form>
    </div>;
}
