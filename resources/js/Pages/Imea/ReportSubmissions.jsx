import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportSubmissionTracker from '@/Pages/Bms/ReportSubmissionTracker';

export default function ReportSubmissions({ submissions, protectedAreas, filters = {}, moduleLabel, routePrefix }) {
    const { auth = {} } = usePage().props;
    return <AuthenticatedLayout title="IMEA Report"><PageHeader title="IMEA Report" description="15-working-day submission compliance tracking." /><div className="mt-6"><ReportSubmissionTracker submissions={submissions} protectedAreas={protectedAreas} filters={filters} moduleLabel={moduleLabel} submissionRoutes={{ store: route(`${routePrefix}.store`), update: id => route(`${routePrefix}.update`, id), destroy: id => route(`${routePrefix}.destroy`, id), mov: report => report.mov_url, index: route(`${routePrefix}.index`) }} filterPrefix="" permissions={{ create: Boolean(auth.canCreateImea), update: Boolean(auth.canUpdateImea), delete: Boolean(auth.canDeleteImea) }} /></div></AuthenticatedLayout>;
}
