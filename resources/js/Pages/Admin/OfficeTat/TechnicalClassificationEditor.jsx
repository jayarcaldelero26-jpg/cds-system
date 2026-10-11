import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';

const inputClass = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-gray-700 dark:bg-gray-950 dark:text-white';

export default function TechnicalClassificationEditor({ items = [], canConfigure = false }) {
    const [selectedKey, setSelectedKey] = useState('');
    const [value, setValue] = useState('');
    const [approval, setApproval] = useState({
        approving_authority: '', approval_reference: '', rationale: '',
        effective_from: '', effective_to: '', evidence_reference: '',
    });
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');
    const options = items.map(item => ({
        id: item.domain + ':' + item.workflow_key,
        label: (item.domain === 'engp' ? 'Development' : 'Conservation') + ' · ' + item.label,
    }));
    const selected = items.find(item => item.domain + ':' + item.workflow_key === selectedKey) || items[0] || null;

    useEffect(() => {
        if (selected && !items.some(item => item.domain + ':' + item.workflow_key === selectedKey)) {
            setSelectedKey(selected.domain + ':' + selected.workflow_key);
        }
    }, [items, selected, selectedKey]);

    useEffect(() => {
        setValue(selected?.is_highly_technical == null ? '' : (selected.is_highly_technical ? 'yes' : 'no'));
        setApproval({
            approving_authority: selected?.approving_authority || '',
            approval_reference: selected?.approval_reference || '',
            rationale: selected?.rationale || '',
            effective_from: selected?.effective_from || '',
            effective_to: selected?.effective_to || '',
            evidence_reference: selected?.evidence_reference || '',
        });
        setError('');
    }, [selected?.domain, selected?.workflow_key, selected?.stored_version]);

    const upload = file => {
        if (!file || !selected) return;
        setUploading(true);
        setError('');
        router.post('/admin/office-tat/approval-evidence', {
            kind: 'classification',
            identity: { domain: selected.domain, workflow_key: selected.workflow_key },
            evidence: file,
        }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: page => setApproval(current => ({
                ...current,
                evidence_reference: page?.props?.assessmentSaved?.evidence_reference || '',
            })),
            onError: messages => setError(Object.values(messages || {})[0] || 'Approval file could not be registered.'),
            onFinish: () => setUploading(false),
        });
    };

    const submit = () => {
        if (!selected) return;
        router.post('/admin/office-tat/technical-classification', {
            domain: selected.domain,
            workflow_key: selected.workflow_key,
            is_highly_technical: value === 'yes',
            ...approval,
            expected_version: Number(selected.stored_version || 0),
        }, { preserveScroll: true });
    };

    const readyToSave = canConfigure && Boolean(
        selected && value && approval.evidence_reference
        && approval.approving_authority.trim() && approval.approval_reference.trim()
        && approval.rationale.trim().length >= 10 && approval.effective_from,
    );
    const setField = (field, nextValue) => setApproval(current => ({ ...current, [field]: nextValue }));
    const Field = ({ label, children }) => <label className="block text-xs font-semibold text-gray-700 dark:text-gray-200">{label}{children}</label>;

    return <details className="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <summary className="cursor-pointer px-4 py-3 text-sm font-extrabold text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700 dark:text-white">
            Technical classification · {items.filter(item => item.is_highly_technical == null).length} workflows need an approved classification
        </summary>
        <div className="space-y-3 border-t border-gray-100 p-4 dark:border-gray-800">
            <p className="text-xs leading-5 text-gray-600 dark:text-gray-300">Classification affects only the final tie-break. Choose one workflow to review its approved source and effective dates.</p>
            <ScopedOptionSelect id="office-tat-classification-workflow" label="Workflow" value={selected ? selected.domain + ':' + selected.workflow_key : ''} options={options} onChange={setSelectedKey} placeholder="Search registered workflows" disabled={!canConfigure} />
            {selected && <div className="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <p className="text-xs font-semibold text-gray-800 dark:text-gray-100">
                    {selected.label}{selected.version ? ' · version ' + selected.version : ' · no approved version'}
                    <span className="mt-1 block text-[10px] font-normal leading-4 text-gray-500 dark:text-gray-400">
                        Start: {selected.start_basis}. Deadline: {selected.deadline_basis}. Endpoint: {selected.submission_endpoint}. {selected.day_convention}{selected.allowed_days ? ' · ' + selected.allowed_days + ' days' : ''}.
                    </span>
                </p>
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    <Field label="Classification"><select disabled={!canConfigure} value={value} onChange={event => setValue(event.target.value)} className={inputClass}><option value="">Choose classification</option><option value="yes">Highly technical</option><option value="no">Not highly technical</option></select></Field>
                    <Field label="Approving authority"><input maxLength={180} disabled={!canConfigure} value={approval.approving_authority} onChange={event => setField('approving_authority', event.target.value)} className={inputClass} /></Field>
                    <Field label="Written approval reference"><input maxLength={180} disabled={!canConfigure} value={approval.approval_reference} onChange={event => setField('approval_reference', event.target.value)} className={inputClass} /></Field>
                    <Field label="Effective from"><input type="date" disabled={!canConfigure} value={approval.effective_from} onChange={event => setField('effective_from', event.target.value)} className={inputClass} /></Field>
                    <Field label="Effective to (optional)"><input type="date" disabled={!canConfigure} value={approval.effective_to} onChange={event => setField('effective_to', event.target.value)} className={inputClass} /></Field>
                    <Field label="Classification rationale"><textarea minLength={10} maxLength={4000} rows={2} disabled={!canConfigure} value={approval.rationale} onChange={event => setField('rationale', event.target.value)} className={inputClass} /></Field>
                    <Field label="Approval source file"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" disabled={!canConfigure || uploading} onChange={event => upload(event.target.files?.[0])} className={inputClass} /><span className="mt-1 block text-[11px] font-normal text-gray-500">{approval.evidence_reference ? 'Supporting file registered.' : 'Upload the approval source file.'}</span></Field>
                </div>
                {error && <p role="alert" className="mt-2 text-xs text-red-700 dark:text-red-300">{error}</p>}
                <button type="button" disabled={!readyToSave || uploading} onClick={submit} className="mt-3 rounded-lg bg-gradient-to-r from-emerald-700 to-teal-700 px-3 py-2 text-[11px] font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">Append approved classification</button>
            </div>}
        </div>
    </details>;
}
