import { useEffect, useRef, useState } from 'react';
import Modal from '@/Components/Modal';
import CrudModalHeader from './CrudModalHeader';
import CrudModalFooter from './CrudModalFooter';
import SaveProgressIndicator from '@/Components/Attachments/SaveProgressIndicator';
import Button from '@/Components/Button';
import useModalFileDrop from '@/Components/Attachments/useModalFileDrop';
import CrudModalCardContext from './CrudModalCardContext';
import { resolveCrudFormInitialFocusSelector } from '@/Components/ModalMotion/initialFocus.mjs';
import { releaseModalFocusTarget } from '@/Components/ModalMotion/focusReturn.mjs';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { MODAL_MOTION_PRESETS } from '@/Components/ModalMotion/presets.mjs';

function formEntries(form) {
    return Array.from(new FormData(form).entries());
}

function entriesMatch(form, savedEntries) {
    const current = formEntries(form);
    return current.length === savedEntries.length && current.every(([name, value], index) => name === savedEntries[index][0] && entryValuesMatch(value, savedEntries[index][1]));
}

function entryValuesMatch(current, saved) {
    if (Object.is(current, saved)) return true;
    if (typeof File !== 'undefined' && current instanceof File && saved instanceof File) {
        return current.name === saved.name
            && current.size === saved.size
            && current.type === saved.type
            && current.lastModified === saved.lastModified;
    }
    return false;
}

export default function CrudFormModal({ open, mode = 'create', icon, title, subtitle, onClose, onExited, returnFocusTarget = null, onSubmit, beforeConfirmation = null, processing = false, progress = null, errors = {}, summaryErrors = null, systemNotice = null, children, preview, busyOverlay = null, busyOverlayPresentation = 'default', canDelete = false, onDelete, canSave = true, backLabel, saveLabel, saveVariant = 'primary', deleteLabel = 'Delete Record', maxWidth = 'max-w-7xl', initialFocusSelector = null, closeOnOverlayClick = false, suspendKeyboardHandling = false, showHeaderClose = true, motion = MODAL_MOTION_PRESETS.form, confirmation = true }) {
    const busy = Boolean(busyOverlay);
    const viewportBusy = busy && busyOverlayPresentation === 'success-sized';
    const requestPending = processing || busy;
    const panelRef = useRef(null);
    const processingRef = useRef(requestPending);
    const overlayRef = useRef(null);
    const busyOverlayRef = useRef(null);
    const errorSummaryRef = useRef(null);
    const restoreFocusRef = useRef(null);
    const confirmationReturnFocusRef = useRef(null);
    const submitHandlerRef = useRef(onSubmit);
    const bypassConfirmationRef = useRef(false);
    const saveInFlightRef = useRef(false);
    const wasRequestPendingRef = useRef(requestPending);
    const confirmClickInFlightRef = useRef(false);
    const pendingEntriesRef = useRef(null);
    const [confirmationOpen, setConfirmationOpen] = useState(false);
    const [confirmationChanged, setConfirmationChanged] = useState(false);
    const [confirmationSubmitting, setConfirmationSubmitting] = useState(false);
    submitHandlerRef.current = onSubmit;
    const resolvedInitialFocusSelector = resolveCrudFormInitialFocusSelector(initialFocusSelector);
    processingRef.current = requestPending;
    const dropMessage = useModalFileDrop(panelRef, open);
    const displayedErrors = summaryErrors ?? errors;
    const errorSignature = JSON.stringify(displayedErrors || {});
    const hasDisplayedErrors = Object.keys(displayedErrors || {}).length > 0;
    const resolvedSaveLabel = saveLabel || (mode === 'edit' ? 'Save Changes' : 'Save Record');
    const confirmationEnabled = canSave && confirmation !== false;
    const confirmationOptions = confirmation && typeof confirmation === 'object' ? confirmation : {};
    const resolvedActionLabel = confirmationOptions.action || resolvedSaveLabel;
    const confirmationTitle = confirmationOptions.title || `${resolvedActionLabel.replace(/[?.!]+$/, '')}?`;
    const confirmationMessage = confirmationOptions.message || `Confirm this action for “${title}”? The current form values will be sent for server validation.`;
    const handleFormSubmit = event => {
        if (!confirmationEnabled) {
            submitHandlerRef.current?.(event);
            return;
        }
        if (bypassConfirmationRef.current) {
            bypassConfirmationRef.current = false;
            if (saveInFlightRef.current || processingRef.current) {
                event.preventDefault();
                return;
            }
            saveInFlightRef.current = true;
            submitHandlerRef.current?.(event);
            return;
        }
        event.preventDefault();
        if (saveInFlightRef.current || processingRef.current) return;
        if (beforeConfirmation?.(event) === false) return;
        pendingEntriesRef.current = formEntries(panelRef.current);
        confirmationReturnFocusRef.current = releaseModalFocusTarget(panelRef.current, document) || panelRef.current;
        setConfirmationChanged(false);
        setConfirmationOpen(true);
    };
    const confirmFormSubmit = () => {
        const form = panelRef.current;
        if (!form || saveInFlightRef.current || processingRef.current || confirmationSubmitting || confirmClickInFlightRef.current) return;
        if (!form.checkValidity()) {
            setConfirmationOpen(false);
            form.reportValidity();
            return;
        }
        if (!pendingEntriesRef.current || !entriesMatch(form, pendingEntriesRef.current)) {
            pendingEntriesRef.current = formEntries(form);
            setConfirmationChanged(true);
            return;
        }
        confirmClickInFlightRef.current = true;
        bypassConfirmationRef.current = true;
        setConfirmationSubmitting(true);
        setConfirmationOpen(false);
        form.requestSubmit();
    };
    useEffect(() => {
        if (viewportBusy) return undefined;
        if (busy) {
            const active = document.activeElement;
            restoreFocusRef.current = panelRef.current?.contains(active) ? active : confirmationReturnFocusRef.current;
            const frame = window.requestAnimationFrame(() => {
                const overlay = busyOverlayRef.current;
                const status = overlay?.querySelector('[data-cds-busy-status]');
                (status || overlay)?.focus({ preventScroll: true });
            });
            return () => window.cancelAnimationFrame(frame);
        }
        if (open && hasDisplayedErrors && !processing) {
            restoreFocusRef.current = null;
            const frame = window.requestAnimationFrame(() => {
                const summary = errorSummaryRef.current;
                if (!summary) return;
                summary.scrollIntoView?.({ block: 'nearest' });
                summary.focus?.({ preventScroll: true });
            });
            return () => window.cancelAnimationFrame(frame);
        }
        const restoreTarget = restoreFocusRef.current;
        restoreFocusRef.current = null;
        if (open && restoreTarget?.isConnected) {
            const frame = window.requestAnimationFrame(() => restoreTarget.focus({ preventScroll: true }));
            return () => window.cancelAnimationFrame(frame);
        }
        return undefined;
    }, [busy, errorSignature, hasDisplayedErrors, open, processing, viewportBusy]);
    useEffect(() => {
        if (requestPending) {
            wasRequestPendingRef.current = true;
            saveInFlightRef.current = true;
            return;
        }
        if (wasRequestPendingRef.current) {
            wasRequestPendingRef.current = false;
            saveInFlightRef.current = false;
            bypassConfirmationRef.current = false;
            pendingEntriesRef.current = null;
            confirmClickInFlightRef.current = false;
            setConfirmationSubmitting(false);
        }
    }, [requestPending]);
    const displayPreview = Boolean(preview);
    return <Modal open={open} title={title} onClose={() => { if (!processingRef.current) onClose?.(); }} onExited={onExited} panelRef={panelRef} layerRef={overlayRef} initialFocusSelector={resolvedInitialFocusSelector} returnFocusTarget={returnFocusTarget} closeOnEscape={!busy} suspendKeyboardHandling={suspendKeyboardHandling} closeOnBackdrop={closeOnOverlayClick && !requestPending} motion={motion} as="form" role="dialog" ariaLabel={title} zIndex={viewportBusy ? 'z-[70]' : 'z-50'} wrapperClassName="h-dvh p-4" backdropClassName={`${viewportBusy ? 'bg-transparent' : 'bg-gray-950/60'} backdrop-blur-xs`} panelClassName={`flex min-h-0 max-h-[min(92dvh,100%)] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900 ${maxWidth}`} panelProps={{ onSubmit: handleFormSubmit, inert: busy || confirmationOpen || undefined, 'aria-hidden': busy || confirmationOpen || undefined, 'data-cds-modal-drop-panel': true }} layerContent={<>
        <Modal open={viewportBusy} title="Request in progress" ariaLabel="Request in progress" motion={MODAL_MOTION_PRESETS.blocking} onClose={() => {}} closeOnEscape={false} closeOnBackdrop={false} initialFocusSelector="[data-cds-busy-status]" panelRef={busyOverlayRef} zIndex="z-[80]" wrapperClassName="p-4" backdropClassName="bg-gray-950/45" panelClassName="flex max-h-full w-full max-w-[390px] min-h-[min(320px,100%)] flex-col justify-center-safe overflow-y-auto rounded-lg bg-white px-7 py-6 text-center shadow-xl dark:bg-gray-900">
            {busyOverlay}
        </Modal>
        {confirmationEnabled && <ConfirmDialog
            open={confirmationOpen}
            title={confirmationTitle}
            message={confirmationChanged ? <>{confirmationMessage}<span className="mt-2 block font-semibold">The form values changed while this confirmation was open. Review the displayed values before continuing.</span></> : confirmationMessage}
            confirmLabel={confirmationOptions.confirmLabel || resolvedActionLabel}
            cancelLabel={confirmationOptions.cancelLabel || 'Go Back'}
            processing={processing || confirmationSubmitting}
            returnFocusTarget={confirmationReturnFocusRef.current}
            onConfirm={confirmFormSubmit}
            onCancel={() => {
                if (processing || confirmationSubmitting) return;
                setConfirmationOpen(false);
                setConfirmationChanged(false);
                pendingEntriesRef.current = null;
                confirmationReturnFocusRef.current = null;
            }}
        />}
    </>}>
            {dropMessage && <div className="cds-modal-drop-feedback" aria-live="polite">{dropMessage}</div>}
            <CrudModalHeader icon={icon} title={title} subtitle={subtitle} onClose={requestPending ? undefined : onClose} showClose={showHeaderClose} />
            <CrudModalCardContext.Provider value><div inert={busy} aria-hidden={busy || undefined} className="custom-table-scrollbar min-h-0 flex-1 overflow-y-auto p-6">
                <SaveProgressIndicator processing={processing} progress={progress} />
                {hasDisplayedErrors && <div ref={errorSummaryRef} tabIndex={-1} className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert"><p className="font-bold">Please correct the following:</p><ul className="mt-1 list-disc pl-5">{Object.values(displayedErrors).map((error, index) => <li key={index}>{error}</li>)}</ul></div>}
                {systemNotice && <div className="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" role="alert"><p className="font-bold">{systemNotice.title}</p><p className="mt-1">{systemNotice.message}</p></div>}
                <div className={displayPreview ? 'grid grid-cols-1 gap-6 lg:grid-cols-12' : ''}><div className={displayPreview ? 'space-y-5 lg:col-span-6' : 'space-y-5'}>{children}</div>{displayPreview && <div className="lg:col-span-6"><div className="sticky top-4">{preview}</div></div>}</div>
            </div></CrudModalCardContext.Provider>
            <div className="shrink-0" inert={busy} aria-hidden={busy || undefined}><CrudModalFooter left={canDelete && onDelete ? <Button type="button" size="compact" variant="danger" onClick={onDelete} disabled={processing} className="rounded-xl px-4 py-2.5 text-xs">{deleteLabel}</Button> : null}>
                <Button type="button" size="compact" variant={backLabel?.toLowerCase().includes('back') || mode === 'edit' ? 'back' : 'cancel'} onClick={onClose} disabled={processing} className="rounded-xl px-4 py-2.5 text-xs">{backLabel || (mode === 'edit' ? '← Back' : 'Cancel')}</Button>
                {canSave && <Button type="submit" size="compact" variant={saveVariant} disabled={processing} className="rounded-xl px-5 py-2.5 text-xs">{processing ? (mode === 'edit' ? 'Updating…' : 'Saving…') : resolvedSaveLabel}</Button>}
            </CrudModalFooter></div>
            {busy && !viewportBusy && <div ref={busyOverlayRef} className="absolute inset-0 z-30 flex items-center justify-center overflow-hidden rounded-2xl bg-slate-50/95 px-5 dark:bg-slate-950/95" data-cds-busy-presentation={busyOverlayPresentation}>
                {busyOverlay}
            </div>}
    </Modal>;
}
