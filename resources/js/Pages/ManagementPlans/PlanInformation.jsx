import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';
import FilePreviewPanel from '@/Components/Crud/FilePreviewPanel';
import { ProfileDetails, ProfileFormModal } from './PlanProfileFields';

const show = value => value === null || value === undefined || value === '' ? '—' : value;
const period = profile => profile?.planning_period_start || profile?.planning_period_end ? `${show(profile.planning_period_start)}–${show(profile.planning_period_end)}` : '—';
const completeness = profile => `${profile.completeness_completed} / ${profile.completeness_total} Complete`;
const buttonClass = 'rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-50';

export default function PlanInformation({ selectedPlanType, planProfile, protectedAreas = [], approvalStatuses = [], documentCategories = {} }) {
    const { auth = {} } = usePage().props;
    const canCreate = Boolean(auth.canCreateManagementPlans);
    const canUpdate = Boolean(auth.canUpdateManagementPlans);
    const profile = planProfile || null;
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const documents = useMemo(() => Array.isArray(profile?.documents) ? profile.documents : [], [profile?.documents]);
    const [activeDocument, setActiveDocument] = useState(documents[0] || null);

    useEffect(() => {
        if (!profile) { setDetailsOpen(false); setActiveDocument(null); return; }
        setActiveDocument(current => documents.find(document => document.path === current?.path) || documents[0] || null);
    }, [profile?.id, profile?.updated_at, documents]);

    return <>
        <section className="mb-5 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div><h2 className="text-xs font-bold uppercase tracking-wide text-green-700 dark:text-green-400">Plan Information / Approval Profile</h2>{profile ? <div className="mt-3 flex flex-wrap gap-2"><span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">Approval: {profile.approval_status}</span><span className="rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-700 dark:bg-green-950 dark:text-green-300">{completeness(profile)}</span></div> : <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Plan information has not been added yet.</p>}</div>
                {profile ? <button type="button" onClick={() => setDetailsOpen(true)} className={`${buttonClass} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700`}>View Plan Information</button> : canCreate ? <button type="button" onClick={() => setEditing(true)} className={`${buttonClass} bg-green-700 text-white shadow-sm hover:bg-green-800`}>+ Add Plan Information</button> : null}
            </div>
        </section>
        {profile && <CrudDetailsModal open={detailsOpen} icon="📁" title={`${selectedPlanType.name} Plan Details`} subtitle={selectedPlanType.description || 'Plan information and approval profile'} onClose={() => setDetailsOpen(false)} canEdit={canUpdate} canDelete={false} onEdit={() => { setEditing(true); setDetailsOpen(false); }} editLabel="Edit Plan Information" summary={<CrudSummaryGrid items={[{ label: 'Protected Area', value: show(profile.protected_area_name) }, { label: 'Approval Stage', value: profile.approval_status }, { label: 'Planning Period', value: period(profile) }, { label: 'Completeness', value: completeness(profile) }]} />} attachments={<div className="space-y-3">{documents.length ? <div className="flex flex-wrap gap-2">{documents.map(document => <button key={document.path} type="button" onClick={() => setActiveDocument(document)} className={`rounded-lg border px-3 py-2 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${activeDocument?.path === document.path ? 'border-green-700 bg-green-700 text-white' : 'border-gray-300 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'}`}>{document.name}</button>)}</div> : <p className="text-xs text-gray-500">No supporting documents.</p>}<FilePreviewPanel file={activeDocument} title="Plan Supporting Document" heightClass="h-[480px]" /></div>}><ProfileDetails profile={profile} /></CrudDetailsModal>}
        {editing && <ProfileFormModal key={profile?.id || 'create'} profile={profile} selectedPlanType={selectedPlanType} protectedAreas={protectedAreas} approvalStatuses={approvalStatuses} documentCategories={documentCategories} onClose={() => setEditing(false)} />}
    </>;
}
