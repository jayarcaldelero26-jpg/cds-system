import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import Button from '@/Components/Button';
import DateConductedRangesField from '@/Components/Reports/DateConductedRangesField';
import DatePicker from '@/Components/DatePicker';
import { FloatingInput, FloatingSelect } from '@/Components/Form';
import ProtectedAreaSelect from '@/Components/Form/ProtectedAreaSelect';
import TargetOfficeSelect from '@/Components/Form/TargetOfficeSelect';

const officeOptions = [
    { id: 'office-baganga', name: 'CENRO Baganga', label: 'CENRO Baganga' },
    { id: 'office-mati', name: 'CENRO Mati', label: 'CENRO Mati' },
];
const areasByOffice = {
    'office-baganga': [
        { id: 'pa-baganga', name: 'Baganga Coastline and Watershed Protected Landscape' },
        { id: 'pa-sample', name: 'Mount Hamiguitan Range Wildlife Sanctuary' },
    ],
    'office-mati': [{ id: 'pa-mati', name: 'Mati Coastal Resource Protected Area' }],
};
const filledRanges = [
    { from: '2026-10-01', to: '2026-10-02' },
    { from: '2026-10-12', to: '2026-10-15' },
    { from: '2026-10-21', to: '2026-10-23' },
];
const emptyRange = () => ({ from: '', to: '' });

function Fixture() {
    const [open, setOpen] = useState(true);
    const [darkMode, setDarkMode] = useState(false);
    const [narrowPanel, setNarrowPanel] = useState(false);
    const [populated, setPopulated] = useState(true);
    const [showErrors, setShowErrors] = useState(false);
    const [activity, setActivity] = useState('Habitat restoration and biodiversity monitoring in the Baganga coastal watershed');
    const [office, setOffice] = useState('office-baganga');
    const [protectedArea, setProtectedArea] = useState('pa-baganga');
    const [documentType, setDocumentType] = useState('Progress Report');
    const [period, setPeriod] = useState('Quarter 3');
    const [dateAccomplished, setDateAccomplished] = useState('2026-10-28');
    const [ranges, setRanges] = useState(filledRanges.map(range => ({ ...range })));
    const [interceptedSubmits, setInterceptedSubmits] = useState(0);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
        return () => document.documentElement.classList.remove('dark');
    }, [darkMode]);

    const togglePopulation = () => {
        const next = !populated;
        setPopulated(next);
        setActivity(next ? 'Habitat restoration and biodiversity monitoring in the Baganga coastal watershed' : '');
        setOffice(next ? 'office-baganga' : '');
        setProtectedArea(next ? 'pa-baganga' : '');
        setDocumentType(next ? 'Progress Report' : '');
        setPeriod(next ? 'Quarter 3' : '');
        setDateAccomplished(next ? '2026-10-28' : '');
        setRanges(next ? filledRanges.map(range => ({ ...range })) : [emptyRange()]);
        setShowErrors(false);
    };

    const updateRange = (index, value) => setRanges(current => current.map((range, at) => at === index
        ? { ...range, from: value?.from || '', to: value?.to || '' }
        : range));

    return <main className="min-h-screen bg-gray-50 p-4 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <div className="mx-auto max-w-5xl pb-4">
            <h1 className="text-lg font-bold">Shared report layout fixture</h1>
            {!open && <Button size="compact" onClick={() => setOpen(true)} className="mt-3">Open review form</Button>}
        </div>

        <CrudFormModal
            open={open}
            mode="create"
            title="Add Report Submission"
            subtitle="Local fixture; no data is sent or saved."
            maxWidth={narrowPanel ? 'max-w-5xl' : 'max-w-7xl'}
            onClose={() => setOpen(false)}
            onSubmit={event => { event.preventDefault(); setInterceptedSubmits(value => value + 1); }}
            confirmation={false}
            canSave
            saveLabel="Save Report"
            errors={{}}
            preview={<aside aria-label="MOV preview" className="min-h-48 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-5 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-300">
                <p className="font-semibold text-gray-800 dark:text-gray-100">MOV preview panel</p>
                <p className="mt-2">Synthetic preview space to exercise the available form width. No file is loaded.</p>
                <p className="mt-4 text-xs">This panel stays open while the form width changes.</p>
            </aside>}
        >
            <div role="group" aria-label="Fixture controls" className="flex flex-wrap items-center gap-2">
                <Button size="compact" variant="secondary" onClick={() => setDarkMode(value => !value)}>{darkMode ? 'Light theme' : 'Dark theme'}</Button>
                <Button size="compact" variant="secondary" onClick={togglePopulation}>{populated ? 'Empty values' : 'Prefilled values'}</Button>
                <Button size="compact" variant="secondary" onClick={() => setShowErrors(value => !value)}>{showErrors ? 'Hide errors' : 'Show errors'}</Button>
                <Button size="compact" variant="secondary" onClick={() => setNarrowPanel(value => !value)}>{narrowPanel ? 'Wide form panel' : 'Narrow form panel'}</Button>
                <output className="text-xs text-gray-500 dark:text-gray-400" aria-live="polite">Intercepted submits: {interceptedSubmits}</output>
            </div>
            <CrudSection title="General / Report Information">
                <div className="cds-report-form-fields-container">
                    <div className="cds-report-form-fields-grid">
                        <div className="min-w-0"><TargetOfficeSelect id="fixture-target-office" value={office} options={officeOptions} required onChange={value => { setOffice(value); setProtectedArea(''); }} /></div>
                        <div className="min-w-0"><ProtectedAreaSelect id="fixture-protected-area" label="Name of PA" value={protectedArea} targetOfficeId={office} protectedAreasByOffice={areasByOffice} required onChange={setProtectedArea} disabled={!office} /></div>
                        <div className="col-span-full min-w-0"><FloatingInput id="fixture-activity" label="Name of Activity" required value={activity} onChange={event => setActivity(event.target.value)} /></div>
                        <div className="min-w-0"><FloatingSelect id="fixture-document" label="Type of Document" required value={documentType} onChange={event => setDocumentType(event.target.value)}>
                            <option value="">Select Type of Document</option><option>Progress Report</option><option>Completion Report</option>
                        </FloatingSelect></div>
                        <div className="min-w-0"><FloatingSelect id="fixture-period" label="Reporting Period" required value={period} onChange={event => setPeriod(event.target.value)}>
                            <option value="">Select Reporting Period</option><option>Quarter 1</option><option>Quarter 2</option><option>Quarter 3</option><option>Quarter 4</option>
                        </FloatingSelect></div>
                        <DateConductedRangesField
                            idPrefix="fixture-date-conducted"
                            rowSetKey="fixture-session"
                            ranges={ranges}
                            required
                            groupError={showErrors ? 'Provide at least one valid Date Conducted range.' : ''}
                            rangeError={(_, index) => showErrors && index === 1 ? 'Choose a start and end date for this row.' : ''}
                            onRangeChange={updateRange}
                            onAddRange={() => setRanges(current => [...current, emptyRange()])}
                            onRemoveRange={index => setRanges(current => current.filter((_, at) => at !== index))}
                        />
                        <div className="min-w-0"><DatePicker id="fixture-date-accomplished" label="Date Accomplished" actualDate required value={dateAccomplished} onChange={setDateAccomplished} /></div>
                    </div>
                </div>
            </CrudSection>
            <div className="sr-only" aria-live="polite">Date rows: {ranges.length}</div>
        </CrudFormModal>
    </main>;
}

createRoot(document.getElementById('root')).render(<Fixture />);
