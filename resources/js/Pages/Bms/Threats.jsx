import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import ConfirmDialog from '@/Components/ConfirmDialog';

const emptyThreat = { protected_area_id: '', date: '', location: '', threat_type: '', threat_detail: '', extent: '', severity: '', coord_format: 'DD', latitude: '', longitude: '', lat_deg: '', lat_min: '', lat_sec: '', long_deg: '', long_min: '', long_sec: '', utm_zone: '', easting: '', northing: '', actions_taken: '', remarks: '' };
const display = value => value === null || value === undefined || value === '' ? '—' : String(value);
const Detail = ({ label, children }) => <div><dt className="text-xs text-gray-500">{label}</dt><dd className="mt-1 font-semibold text-gray-800 dark:text-gray-200">{children}</dd></div>;

const coordinates = threat => {
    if (threat.coord_format === 'DMS') return `${display(threat.lat_deg)}° ${display(threat.lat_min)}' ${display(threat.lat_sec)}", ${display(threat.long_deg)}° ${display(threat.long_min)}' ${display(threat.long_sec)}"`;
    if (threat.coord_format === 'UTM') return `${display(threat.utm_zone)} · E ${display(threat.easting)} · N ${display(threat.northing)}`;
    return `${display(threat.latitude)}, ${display(threat.longitude)}`;
};

export default function Threats({ threats = [], protectedAreas = [] }) {
    const { auth = {} } = usePage().props;
    const canCreate = Boolean(auth.canCreateBms);
    const canUpdate = Boolean(auth.canUpdateBms);
    const canDelete = Boolean(auth.canDeleteBms);
    const [areaFilter, setAreaFilter] = useState('all');
    const [typeFilter, setTypeFilter] = useState('all');
    const [selectedThreat, setSelectedThreat] = useState(null);
    const [modal, setModal] = useState(null);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const form = useForm(emptyThreat);

    const threatTypes = useMemo(() => [...new Set(threats.map(threat => threat.threat_type).filter(Boolean))].sort(), [threats]);
    const filteredThreats = useMemo(() => threats.filter(threat => (areaFilter === 'all' || String(threat.protected_area_id) === areaFilter) && (typeFilter === 'all' || threat.threat_type === typeFilter)), [threats, areaFilter, typeFilter]);

    const closeAll = () => { setModal(null); setSelectedThreat(null); form.reset(); form.clearErrors(); };
    const openDetails = threat => { setSelectedThreat(threat); setModal('details'); };
    const openCreate = () => { setSelectedThreat(null); form.reset(); form.clearErrors(); setModal('create'); };
    const openEdit = threat => { setSelectedThreat(threat); form.clearErrors(); form.setData(Object.fromEntries(Object.keys(emptyThreat).map(key => [key, threat[key] ?? emptyThreat[key]]))); setModal('edit'); };
    const backFromForm = () => { form.clearErrors(); setModal(selectedThreat ? 'details' : null); };
    const submit = event => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: closeAll };
        modal === 'edit'
            ? form.put(route('bms.threats.update', selectedThreat.id), options)
            : form.post(route('bms.threats.store'), options);
    };
    const confirmDelete = () => {
        if (!deleteTarget || !canDelete || deleteProcessing) return;
        setDeleteProcessing(true);
        router.delete(route('bms.threats.destroy', deleteTarget.id), { preserveScroll: true, onSuccess: () => { setDeleteTarget(null); closeAll(); }, onFinish: () => setDeleteProcessing(false) });
    };

    const input = 'mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-green-600 focus:ring-green-600 dark:border-gray-700 dark:bg-gray-800 dark:text-white';
    const label = 'block text-xs font-semibold text-gray-700 dark:text-gray-300';
    const error = name => form.errors[name] && <span className="mt-1 block text-xs text-red-500">{form.errors[name]}</span>;
    const field = (name, text, options = {}) => <label className={label}>{text}<input type={options.type || 'text'} value={form.data[name]} onChange={event => form.setData(name, event.target.value)} className={input} required={options.required} />{error(name)}</label>;

    const columns = [
        { key: 'date_location', label: 'Date / Location', render: threat => <><div className="font-semibold text-gray-900 dark:text-white">{display(threat.date)}</div><div className="text-xs text-gray-500">{display(threat.location)}</div></> },
        { key: 'classification', label: 'Threat Classification', render: threat => <><div className="font-semibold text-red-700 dark:text-red-400">{display(threat.threat_type)}</div><div className="text-xs text-gray-500">{display(threat.threat_detail)}</div><div className="mt-1 text-xs font-semibold">{display(threat.severity)}</div></> },
        { key: 'extent', label: 'Extent / Scale of Impact', render: threat => display(threat.extent) },
        { key: 'coordinates', label: 'Coordinates', cellClassName: 'font-mono text-xs', render: coordinates },
        { key: 'actions_taken', label: 'Actions Taken', render: threat => display(threat.actions_taken) },
        { key: 'remarks', label: 'Remarks', render: threat => display(threat.remarks) },
    ];

    return <div className="space-y-4">
        <div className="flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-xl dark:border-gray-800 dark:bg-gray-900">
            <div className="flex flex-wrap gap-3"><label className={label}>Protected Area<select value={areaFilter} onChange={event => setAreaFilter(event.target.value)} className={input}><option value="all">All Protected Areas</option>{protectedAreas.map(area => <option key={area.id} value={String(area.id)}>{area.name}</option>)}</select></label><label className={label}>Threat Category<select value={typeFilter} onChange={event => setTypeFilter(event.target.value)} className={input}><option value="all">All Threat Types</option>{threatTypes.map(type => <option key={type}>{type}</option>)}</select></label></div>
            {canCreate && <button type="button" onClick={openCreate} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800">+ Add Threat Record</button>}
        </div>

        <CrudTable title="BMS Threat Monitoring" subtitle={`${filteredThreats.length} threat record${filteredThreats.length === 1 ? '' : 's'}`} helperText="Click any row to view full details" caption="BMS threat monitoring records" columns={columns} rows={filteredThreats} rowKey="id" onRowClick={openDetails} emptyTitle="No threat records found" emptyDescription="No persisted BMS threat records match the selected filters." />

        <CrudDetailsModal open={modal === 'details' && Boolean(selectedThreat)} title="BMS Threat Full Details" subtitle={selectedThreat ? `${selectedThreat.protected_area?.name || 'No protected area'} · ${selectedThreat.location || 'No location'}` : ''} onClose={closeAll} canEdit={canUpdate} canDelete={canDelete} onEdit={() => openEdit(selectedThreat)} onDelete={() => setDeleteTarget(selectedThreat)} editLabel="Edit This Threat" summary={selectedThreat && <CrudSummaryGrid items={[{ label: 'Date', value: display(selectedThreat.date) }, { label: 'Threat Type', value: display(selectedThreat.threat_type) }, { label: 'Severity', value: display(selectedThreat.severity) }, { label: 'Extent', value: display(selectedThreat.extent) }]} />}>
            {selectedThreat && <div className="space-y-6"><CrudSection title="Threat Information"><dl className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Detail label="Protected Area">{display(selectedThreat.protected_area?.name)}</Detail><Detail label="Location">{display(selectedThreat.location)}</Detail><Detail label="Threat Type">{display(selectedThreat.threat_type)}</Detail><Detail label="Threat Detail">{display(selectedThreat.threat_detail)}</Detail><Detail label="Extent / Scale">{display(selectedThreat.extent)}</Detail><Detail label="Severity">{display(selectedThreat.severity)}</Detail></dl></CrudSection><CrudSection title="Coordinates"><dl className="grid grid-cols-1 gap-4 sm:grid-cols-2"><Detail label="Coordinate Format">{display(selectedThreat.coord_format)}</Detail><Detail label="Coordinates">{coordinates(selectedThreat)}</Detail></dl></CrudSection><CrudSection title="Response & Remarks"><dl className="space-y-4"><Detail label="Actions Taken">{display(selectedThreat.actions_taken)}</Detail><Detail label="Remarks">{display(selectedThreat.remarks)}</Detail></dl></CrudSection></div>}
        </CrudDetailsModal>

        <CrudFormModal open={modal === 'create' || modal === 'edit'} mode={modal === 'edit' ? 'edit' : 'create'} icon="⚠️" title={modal === 'edit' ? 'Edit BMS Threat' : 'Add BMS Threat'} subtitle="Record the persisted threat classification, location, coordinates, and response." onClose={backFromForm} onSubmit={submit} processing={form.processing} errors={form.errors} canDelete={modal === 'edit' && canDelete} onDelete={() => setDeleteTarget(selectedThreat)} maxWidth="max-w-4xl">
            <CrudSection title="Threat Information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2"><label className={label}>Protected Area<select value={form.data.protected_area_id} onChange={event => form.setData('protected_area_id', event.target.value)} className={input}><option value="">Select Protected Area</option>{protectedAreas.map(area => <option key={area.id} value={area.id}>{area.name}</option>)}</select>{error('protected_area_id')}</label>{field('date', 'Date *', { type: 'date', required: true })}{field('location', 'Location')}{field('threat_type', 'Threat Type *', { required: true })}{field('threat_detail', 'Threat Detail')}{field('extent', 'Extent / Scale of Impact')}{field('severity', 'Severity')}</div></CrudSection>
            <CrudSection title="Coordinates"><div className="space-y-4"><label className={label}>Coordinate Format<select value={form.data.coord_format} onChange={event => form.setData('coord_format', event.target.value)} className={input}><option value="DD">Decimal Degrees (DD)</option><option value="DMS">Degrees, Minutes, Seconds (DMS)</option><option value="UTM">UTM Zone</option></select>{error('coord_format')}</label>{form.data.coord_format === 'DD' && <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{field('latitude', 'Latitude')}{field('longitude', 'Longitude')}</div>}{form.data.coord_format === 'DMS' && <div className="grid grid-cols-3 gap-3">{field('lat_deg', 'Latitude Degrees')}{field('lat_min', 'Latitude Minutes')}{field('lat_sec', 'Latitude Seconds')}{field('long_deg', 'Longitude Degrees')}{field('long_min', 'Longitude Minutes')}{field('long_sec', 'Longitude Seconds')}</div>}{form.data.coord_format === 'UTM' && <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">{field('utm_zone', 'UTM Zone')}{field('easting', 'Easting')}{field('northing', 'Northing')}</div>}</div></CrudSection>
            <CrudSection title="Response & Remarks"><div className="space-y-4">{field('actions_taken', 'Actions Taken')}<label className={label}>Remarks<textarea rows="4" value={form.data.remarks} onChange={event => form.setData('remarks', event.target.value)} className={input} />{error('remarks')}</label></div></CrudSection>
        </CrudFormModal>

        <ConfirmDialog open={Boolean(deleteTarget) && canDelete} variant="danger" title="Delete BMS Threat?" message={`Delete the threat record for “${deleteTarget?.threat_type || 'this threat'}”? This cannot be undone.`} confirmLabel="Delete Record" onConfirm={confirmDelete} onCancel={() => setDeleteTarget(null)} processing={deleteProcessing} />
    </div>;
}
