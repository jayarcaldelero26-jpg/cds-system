import { Icon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import CrudModalFooter from '@/Components/Crud/CrudModalFooter';
import CrudModalHeader from '@/Components/Crud/CrudModalHeader';

const formats = [
    {
        key: 'pdf',
        title: 'PDF DOCUMENT',
        extension: '.pdf',
        description: 'Best for printing and official submission',
        icon: 'solar:file-text-linear',
    },
    {
        key: 'xlsx',
        title: 'EXCEL WORKBOOK',
        extension: '.xlsx',
        description: 'Best for analysis and editable numeric data',
        icon: 'solar:chart-2-linear',
    },
    {
        key: 'docx',
        title: 'WORD DOCUMENT',
        extension: '.docx',
        description: 'Best for editable report preparation',
        icon: 'solar:document-text-linear',
    },
];

const formatNames = { pdf: 'PDF', xlsx: 'Excel', docx: 'Word' };

export default function AwsSummaryExportModal({ open, onClose, onExport, returnFocusRef, summaryType, reportingPeriod, protectedArea }) {
    const dialogRef = useRef(null);
    const firstCardRef = useRef(null);
    const generatingRef = useRef('');
    const [selectedFormat, setSelectedFormat] = useState('');
    const [generating, setGenerating] = useState('');
    const [error, setError] = useState('');
    generatingRef.current = generating;

    useEffect(() => {
        if (!open) return undefined;
        setSelectedFormat('');
        setGenerating('');
        setError('');
        const previousFocus = document.activeElement;

        const focusFirstCard = () => firstCardRef.current?.focus();
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                if (!generatingRef.current) { onClose?.(); window.requestAnimationFrame(() => returnFocusRef?.current?.focus()); }
                return;
            }
            if (event.key !== 'Tab' || !dialogRef.current) return;
            const focusable = [...dialogRef.current.querySelectorAll('button:not([disabled])')];
            if (!focusable.length) return;
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown, true);
        const focusTimer = window.requestAnimationFrame(focusFirstCard);
        return () => {
            window.cancelAnimationFrame(focusTimer);
            document.removeEventListener('keydown', handleKeyDown, true);
            if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
        };
    }, [open]);

    if (!open) return null;

    const close = (force = false) => {
        if (generating && !force) return;
        onClose?.();
        window.requestAnimationFrame(() => returnFocusRef?.current?.focus());
    };

    const chooseFormat = (format) => {
        if (generating) return;
        setError('');
        setSelectedFormat(format);
    };

    const exportFile = async () => {
        if (!selectedFormat || generating) return;
        setGenerating(selectedFormat);
        setError('');
        try {
            await onExport(selectedFormat);
            close(true);
        } catch {
            setError('The export could not be generated. Please try again.');
        } finally {
            setGenerating('');
        }
    };

    const generatingLabel = generating ? `Generating ${formatNames[generating]}...` : '';

    return <div className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-xs" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) close(); }}>
        <div ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="aws-export-title" aria-describedby="aws-export-subtitle" className="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900">
            <CrudModalHeader title="Export AWS Monitoring Summary" subtitle="Choose the file format for the current monitoring report." onClose={generating ? undefined : close} />
            <div className="custom-table-scrollbar min-h-0 overflow-y-auto p-5 sm:p-6">
                <div id="aws-export-title" className="sr-only">Export AWS Monitoring Summary</div>
                <div id="aws-export-subtitle" className="sr-only">Choose the file format for the current monitoring report.</div>
                <dl className="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-700 dark:bg-gray-800/60 sm:grid-cols-3">
                    <div><dt className="font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Summary Type</dt><dd className="mt-1 font-bold text-gray-900 dark:text-white">{summaryType}</dd></div>
                    <div><dt className="font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Reporting Period</dt><dd className="mt-1 font-bold text-gray-900 dark:text-white">{reportingPeriod}</dd></div>
                    <div><dt className="font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Protected Area</dt><dd className="mt-1 font-bold text-gray-900 dark:text-white">{protectedArea}</dd></div>
                </dl>

                <div className="mt-5 grid gap-3 md:grid-cols-3" role="radiogroup" aria-label="Choose export format">
                    {formats.map((format, index) => {
                        const selected = selectedFormat === format.key;
                        return <button key={format.key} ref={index === 0 ? firstCardRef : undefined} type="button" role="radio" aria-checked={selected} disabled={Boolean(generating)} onClick={() => chooseFormat(format.key)} className={`relative flex min-h-36 flex-col items-start rounded-xl border p-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 ${selected ? 'border-green-600 bg-green-50/80 text-green-950 ring-1 ring-green-600 dark:border-green-500 dark:bg-green-950/30 dark:text-green-100' : 'border-gray-200 bg-white hover:border-green-300 hover:bg-green-50/40 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-green-700 dark:hover:bg-green-950/20'}`}>
                            <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${selected ? 'bg-green-100 text-green-700 dark:bg-green-900/70 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'}`}><Icon icon={format.icon} width="20" height="20" aria-hidden="true" /></span>
                            <span className="mt-3 text-xs font-extrabold tracking-wide">{format.title}</span>
                            <span className="mt-1 text-xs font-semibold text-gray-500 dark:text-gray-400">{format.extension}</span>
                            <span className="mt-2 text-[11px] leading-4 text-gray-600 dark:text-gray-300">{format.description}</span>
                            {selected && <span className="absolute right-3 top-3 flex h-5 w-5 items-center justify-center rounded-full bg-green-700 text-white" aria-label="Selected"><Icon icon="solar:check-read-linear" width="13" height="13" aria-hidden="true" /></span>}
                        </button>;
                    })}
                </div>
                {generating && <p className="mt-4 text-xs font-semibold text-green-700 dark:text-green-300" role="status" aria-live="polite">{generatingLabel}</p>}
                {error && <p className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300" role="alert">{error}</p>}
            </div>
            <CrudModalFooter>
                <button type="button" onClick={close} disabled={Boolean(generating)} className="rounded-xl border border-gray-300 px-4 py-2.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</button>
                <button type="button" onClick={exportFile} disabled={!selectedFormat || Boolean(generating)} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50">{generating ? generatingLabel : 'Export File'}</button>
            </CrudModalFooter>
        </div>
    </div>;
}
