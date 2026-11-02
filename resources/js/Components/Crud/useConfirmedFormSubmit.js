import React, { useEffect, useRef, useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';

function formEntries(form) {
    return Array.from(new FormData(form).entries());
}

function entriesMatch(form, savedEntries) {
    const current = formEntries(form);
    return current.length === savedEntries.length
        && current.every(([name, value], index) => name === savedEntries[index][0] && entryValuesMatch(value, savedEntries[index][1]));
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

export function useConfirmedFormSubmit({ processing = false, onSubmit }) {
    const formRef = useRef(null);
    const processingRef = useRef(processing);
    const submitHandlerRef = useRef(onSubmit);
    const bypassConfirmationRef = useRef(false);
    const requestInFlightRef = useRef(false);
    const confirmClickInFlightRef = useRef(false);
    const wasProcessingRef = useRef(processing);
    const pendingEntriesRef = useRef(null);
    const [confirmationOpen, setConfirmationOpen] = useState(false);
    const [confirmationChanged, setConfirmationChanged] = useState(false);
    const [confirmationSubmitting, setConfirmationSubmitting] = useState(false);

    processingRef.current = processing;
    submitHandlerRef.current = onSubmit;

    const handleSubmit = event => {
        const form = event.currentTarget;
        if (bypassConfirmationRef.current) {
            bypassConfirmationRef.current = false;
            if (requestInFlightRef.current || processingRef.current) {
                event.preventDefault();
                return;
            }
            requestInFlightRef.current = true;
            submitHandlerRef.current?.(event);
            return;
        }

        event.preventDefault();
        if (requestInFlightRef.current || processingRef.current || !form) return;
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        formRef.current = form;
        pendingEntriesRef.current = formEntries(form);
        setConfirmationChanged(false);
        setConfirmationOpen(true);
    };

    const confirmSubmit = () => {
        const form = formRef.current;
        if (!form || requestInFlightRef.current || processingRef.current || confirmationSubmitting || confirmClickInFlightRef.current) return;
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
        form.requestSubmit();
    };

    const cancelConfirmation = () => {
        if (processingRef.current || confirmationSubmitting) return;
        setConfirmationOpen(false);
        setConfirmationChanged(false);
        pendingEntriesRef.current = null;
    };

    const releaseRequest = () => {
        if (processingRef.current) return;
        requestInFlightRef.current = false;
        bypassConfirmationRef.current = false;
        confirmClickInFlightRef.current = false;
        setConfirmationSubmitting(false);
        setConfirmationOpen(false);
        setConfirmationChanged(false);
    };

    useEffect(() => {
        if (processing) {
            wasProcessingRef.current = true;
            requestInFlightRef.current = true;
            return;
        }
        if (wasProcessingRef.current) {
            wasProcessingRef.current = false;
            requestInFlightRef.current = false;
            bypassConfirmationRef.current = false;
            confirmClickInFlightRef.current = false;
            pendingEntriesRef.current = null;
            setConfirmationSubmitting(false);
            setConfirmationOpen(false);
            setConfirmationChanged(false);
        }
    }, [processing]);

    return {
        formRef,
        handleSubmit,
        confirmSubmit,
        cancelConfirmation,
        releaseRequest,
        confirmationOpen,
        confirmationChanged,
        confirmationSubmitting,
    };
}

export function ConfirmedSubmitDialog({ gate, title, message, confirmLabel, cancelLabel = 'Go Back', processing = false }) {
    const displayMessage = gate.confirmationChanged
        ? React.createElement(React.Fragment, null, message, React.createElement('span', { className: 'mt-2 block font-semibold' }, 'The form values changed while this confirmation was open. Review the displayed values before continuing.'))
        : message;

    return React.createElement(ConfirmDialog, {
        open: gate.confirmationOpen,
        title,
        message: displayMessage,
        confirmLabel,
        cancelLabel,
        processing: processing || gate.confirmationSubmitting,
        onConfirm: gate.confirmSubmit,
        onCancel: gate.cancelConfirmation,
    });
}
