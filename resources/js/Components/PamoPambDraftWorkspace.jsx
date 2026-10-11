import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Card from '@/Components/Card';
import DatePicker from '@/Components/DatePicker';
import { FloatingInput, FloatingSelect, FloatingTextarea } from '@/Components/Form';
import PrimaryButton from '@/Components/PrimaryButton';
import Button from '@/Components/Button';
import { createUuid } from '@/Utils/createUuid.mjs';

const emptyDraft = { activity_name: '', document_type: '', reporting_period: '', date_conducted: '', date_accomplished: '', remarks: '', mov: null, lock_version: 1 };

export default function PamoPambDraftWorkspace({ workflow, drafts = [] }) {
    const [selectedId, setSelectedId] = useState('');
    const token = useRef(createUuid());
    const form = useForm(emptyDraft);
    const forwardForm = useForm({ lock_version: 1 });
    const selected = drafts.find(draft => draft.id === selectedId) || null;
    const documentOptions = useMemo(() => {
        const byActivity = workflow.activity_documents || {};
        return byActivity[form.data.activity_name] || workflow.documents || [];
    }, [workflow.activity_documents, workflow.documents, form.data.activity_name]);

    useEffect(() => {
        if (!selectedId) return;
        const latest = drafts.find(draft => draft.id === selectedId);
        if (latest && !form.processing) form.setData('lock_version', latest.lock_version);
    }, [drafts, selectedId]);

    const startNew = () => {
        setSelectedId('');
        form.clearErrors();
        forwardForm.clearErrors();
        form.setData({ ...emptyDraft });
    };

    const openDraft = draft => {
        setSelectedId(draft.id);
        form.clearErrors();
        forwardForm.clearErrors();
        form.setData({ ...emptyDraft, ...(draft.report_payload || {}), mov: null, lock_version: draft.lock_version });
        forwardForm.setData('lock_version', draft.lock_version);
    };

    const save = event => {
        event.preventDefault();
        if (selected) {
            form.transform(data => ({ ...data, lock_version: selected.lock_version }));
            form.put(route('conservation-reports.pamo-drafts.update', [workflow.key, selected.id]), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => form.transform(data => data),
                onError: () => form.transform(data => data),
            });
            return;
        }

        form.transform(data => ({ ...data, start_idempotency_key: token.current }));
        form.post(route('conservation-reports.pamo-drafts.store', workflow.key), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.transform(data => data);
                token.current = createUuid();
                form.setData({ ...emptyDraft });
                setSelectedId('');
            },
            onError: () => form.transform(data => data),
        });
    };

    const forward = event => {
        event.preventDefault();
        if (!selected) return;
        forwardForm.setData('lock_version', selected.lock_version);
        forwardForm.post(route('conservation-reports.pamo-drafts.forward', [workflow.key, selected.id]), {
            preserveScroll: true,
            onError: errors => Object.entries(errors).forEach(([name, message]) => form.setError(name, message)),
            onSuccess: () => { setSelectedId(''); form.setData({ ...emptyDraft }); },
        });
    };

    const forwarded = selected?.status === 'forwarded';
    return <section className="mb-5 space-y-4" aria-labelledby="pamo-pamb-drafts-heading">
        <Card className="border-emerald-200 dark:border-emerald-900">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-800 dark:text-emerald-300">PAMO preparation</p>
                    <h2 id="pamo-pamb-drafts-heading" className="mt-1 text-lg font-extrabold text-gray-950 dark:text-white">PAMB report drafts</h2>
                    <p className="mt-1 max-w-3xl text-xs leading-5 text-gray-600 dark:text-gray-300">Start and save a partial draft any time. Your preparation clock starts when the first draft is saved. Forward checks the complete report and MOV before creating an operational report.</p>
                </div>
                <Button type="button" size="compact" onClick={startNew}>New draft</Button>
            </header>

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(19rem,0.82fr)]">
                <form onSubmit={save} className="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-gray-800 sm:p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-bold text-gray-900 dark:text-white">{selected ? (forwarded ? 'Forwarded report' : 'Continue draft') : 'Start report preparation'}</h3>
                        {selected && <span className="text-[11px] text-gray-500 dark:text-gray-400">Started {formatManila(selected.started_at)}</span>}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FloatingSelect label="Activity" required size="sm" value={form.data.activity_name || ''} disabled={forwarded} onChange={event => form.setData({ ...form.data, activity_name: event.target.value, document_type: '' })} error={form.errors.activity_name}>
                            <option value="">Select activity</option>
                            {(workflow.activities || []).map(activity => <option key={activity} value={activity}>{activity}</option>)}
                        </FloatingSelect>
                        <FloatingSelect label="Document type" size="sm" value={form.data.document_type || ''} disabled={forwarded} onChange={event => form.setData('document_type', event.target.value)} error={form.errors.document_type}>
                            <option value="">Select document type</option>
                            {documentOptions.map(document => <option key={document} value={document}>{document}</option>)}
                        </FloatingSelect>
                        <FloatingSelect label={workflow.period_label || 'Reporting period'} size="sm" value={form.data.reporting_period || ''} disabled={forwarded} onChange={event => form.setData('reporting_period', event.target.value)} error={form.errors.reporting_period}>
                            <option value="">Select period (if applicable)</option>
                            {(workflow.periods || []).map(period => <option key={period} value={period}>{period}</option>)}
                        </FloatingSelect>
                        <DatePicker id="pamo-pamb-date-conducted" label="Date conducted" required value={form.data.date_conducted || ''} actualDate disabled={forwarded} onChange={value => form.setData('date_conducted', value)} error={form.errors.date_conducted} />
                        <DatePicker id="pamo-pamb-date-accomplished" label="Date accomplished" value={form.data.date_accomplished || ''} actualDate disabled={forwarded} onChange={value => form.setData('date_accomplished', value)} error={form.errors.date_accomplished} />
                        <div className="sm:col-span-2">
                            <label htmlFor="pamo-pamb-mov" className="mb-1.5 block text-xs font-semibold text-gray-700 dark:text-gray-300">Report / MOV {selected?.mov_file_name && <span className="font-normal text-gray-500">· {selected.mov_file_name}</span>}</label>
                            {!forwarded && <input id="pamo-pamb-mov" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={event => form.setData('mov', event.target.files?.[0] || null)} className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-emerald-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:file:bg-emerald-950 dark:file:text-emerald-200" />}
                            {selected?.mov_url && <a href={selected.mov_url} target="_blank" rel="noreferrer" className="mt-2 inline-flex text-xs font-semibold text-emerald-800 underline dark:text-emerald-300">Open saved MOV</a>}
                            {form.errors.mov && <p className="mt-1 text-xs text-red-600" role="alert">{form.errors.mov}</p>}
                            {!forwarded && <p className="mt-1 text-[11px] text-gray-500 dark:text-gray-400">PDF, image or Office document · maximum 100 MB · saved privately.</p>}
                        </div>
                        <FloatingTextarea label="Remarks" value={form.data.remarks || ''} disabled={forwarded} onChange={event => form.setData('remarks', event.target.value)} error={form.errors.remarks} className="sm:col-span-2" rows={3} />
                    </div>
                    {!forwarded && <div className="flex flex-wrap items-center gap-2 pt-1">
                        <PrimaryButton type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : selected ? 'Save draft' : 'Start and save draft'}</PrimaryButton>
                        {selected && selected.status === 'draft' && <Button type="button" variant="success" disabled={forwardForm.processing || !selected.mov_file_name} onClick={forward}>{forwardForm.processing ? 'Forwarding…' : 'Forward completed report'}</Button>}
                        {(form.errors.draft || forwardForm.errors.draft) && <p role="alert" className="w-full text-xs text-red-600">{form.errors.draft || forwardForm.errors.draft}</p>}
                    </div>}
                    {selected?.source_url && <p className="text-xs text-emerald-800 dark:text-emerald-300">This draft was published. <a href={selected.source_url} className="font-semibold underline">Open operational report</a></p>}
                </form>

                <aside className="rounded-xl border border-gray-200 p-3 dark:border-gray-800 sm:p-4" aria-label="Saved PAMO PAMB drafts">
                    <div className="flex items-center justify-between gap-3"><h3 className="text-sm font-bold text-gray-900 dark:text-white">Saved work</h3><span className="text-[11px] text-gray-500 dark:text-gray-400">{drafts.length} report{drafts.length === 1 ? '' : 's'}</span></div>
                    {drafts.length === 0 ? <p className="mt-3 rounded-lg bg-gray-50 px-3 py-4 text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-300">No drafts yet. Start one when report preparation begins.</p> : <ul className="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        {drafts.map(draft => <li key={draft.id} className="py-2 first:pt-0 last:pb-0">
                            <button type="button" onClick={() => openDraft(draft)} aria-pressed={selectedId === draft.id} className={`w-full rounded-lg px-3 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 ${selectedId === draft.id ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'hover:bg-gray-50 dark:hover:bg-gray-950'}`}>
                                <span className="flex items-start justify-between gap-2"><span className="text-xs font-semibold text-gray-900 dark:text-white">{draft.report_payload?.activity_name || 'Untitled PAMB draft'}</span><span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${draft.status === 'forwarded' ? 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200' : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200'}`}>{draft.status === 'forwarded' ? 'Forwarded' : 'Draft'}</span></span>
                                <span className="mt-1 block text-[11px] text-gray-500 dark:text-gray-400">{draft.report_payload?.reporting_period || 'No period'} · started {formatManila(draft.started_at)}</span>
                            </button>
                        </li>)}
                    </ul>}
                    <p className="mt-4 rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2.5 text-[11px] leading-4 text-blue-900 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-200">Drafts stay out of live queues, received volumes, routing history, calendars and overdue report counts until Forward succeeds.</p>
                </aside>
            </div>
        </Card>
    </section>;
}

function formatManila(value) {
    if (!value) return 'time unavailable';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'time unavailable';
    return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(date);
}
