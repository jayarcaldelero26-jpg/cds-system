import { useEffect, useState } from 'react';
import { assignDroppedFiles, getDropTarget, validateDroppedFiles } from './modalFileDrop.mjs';

export default function useModalFileDrop(panelRef, active = true) {
    const [message, setMessage] = useState('');
    useEffect(() => {
        const panel = active ? panelRef.current : null;
        if (!panel) return undefined;
        let activeInput = null;
        let feedbackTimer = null;
        const showFeedback = (value) => {
            window.clearTimeout(feedbackTimer);
            setMessage(value);
            if (value && value !== 'Drop files to attach') feedbackTimer = window.setTimeout(() => setMessage(''), 3500);
        };
        const getInputs = () => Array.from(panel.querySelectorAll('input[type="file"]'));
        const markActive = (event) => {
            if (event.target?.matches?.('input[type="file"]')) activeInput = event.target;
        };
        const onDragEnter = (event) => {
            if (!Array.from(event.dataTransfer?.types || []).includes('Files')) return;
            panel.dataset.cdsModalDragover = 'true';
            showFeedback('Drop files to attach');
        };
        const onDragLeave = (event) => {
            if (panel.contains(event.relatedTarget)) return;
            delete panel.dataset.cdsModalDragover;
            showFeedback('');
        };
        const onDrop = (event) => {
            const files = Array.from(event.dataTransfer?.files || []);
            delete panel.dataset.cdsModalDragover;
            showFeedback('');
            if (!files.length) return;
            const directZone = event.target?.closest?.('[data-cds-upload-zone]');
            const directInput = directZone?.querySelector?.('input[type="file"]') || (event.target?.matches?.('input[type="file"]') ? event.target : null);
            if (directInput?.disabled) {
                event.preventDefault();
                showFeedback('This file field is unavailable.');
                return;
            }
            const inputs = getInputs();
            const target = getDropTarget(inputs, {
                direct: directInput,
                active: activeInput,
                primary: panel.querySelector('input[type="file"][data-cds-modal-primary="true"]'),
            });
            if (!target) {
                event.preventDefault();
                showFeedback('Choose a specific file drop zone to attach this file.');
                return;
            }
            const result = validateDroppedFiles(files, target);
            if (!result.files.length) {
                event.preventDefault();
                showFeedback(result.error || 'This file is not accepted here.');
                return;
            }
            event.preventDefault();
            if (directZone?.matches('[data-cds-attachment-dropzone]')) return;
            assignDroppedFiles(target, result.files);
            if (result.error) showFeedback(result.error);
        };
        const onDragOver = (event) => { if (Array.from(event.dataTransfer?.types || []).includes('Files')) event.preventDefault(); };
        panel.addEventListener('focusin', markActive);
        panel.addEventListener('pointerdown', markActive);
        panel.addEventListener('dragenter', onDragEnter);
        panel.addEventListener('dragover', onDragOver);
        panel.addEventListener('dragleave', onDragLeave);
        panel.addEventListener('drop', onDrop);
        return () => {
            panel.removeEventListener('focusin', markActive);
            panel.removeEventListener('pointerdown', markActive);
            panel.removeEventListener('dragenter', onDragEnter);
            panel.removeEventListener('dragover', onDragOver);
            panel.removeEventListener('dragleave', onDragLeave);
            panel.removeEventListener('drop', onDrop);
            window.clearTimeout(feedbackTimer);
            delete panel.dataset.cdsModalDragover;
        };
    }, [panelRef, active]);
    return message;
}
