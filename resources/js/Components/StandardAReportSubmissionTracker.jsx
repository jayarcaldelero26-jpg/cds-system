import { Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportSubmissionTracker from '@/Pages/Bms/ReportSubmissionTracker';

export default function StandardAReportSubmissionTracker({ submissions, protectedAreas, filters = {}, moduleLabel, routePrefix, backRoute }) {
    const { auth = {} } = usePage().props;
    const permissionStem = moduleLabel === 'BAMS' ? 'Bams' : 'Imea';

    return <AuthenticatedLayout title={`${moduleLabel} Report Submission Tracker`}>
        <PageHeader
            title={`${moduleLabel} Report Submission Tracker`}
            description="15-working-day submission compliance tracking."
            actions={<Link href={route(backRoute)} className="rounded-xl bg-white/10 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-white/20">← Back to {moduleLabel}</Link>}
        />
        <div className="mt-6">
            <ReportSubmissionTracker
                submissions={submissions}
                protectedAreas={protectedAreas}
                filters={filters}
                moduleLabel={moduleLabel}
                submissionRoutes={{
                    store: route(`${routePrefix}.store`),
                    update: id => route(`${routePrefix}.update`, id),
                    destroy: id => route(`${routePrefix}.destroy`, id),
                    mov: report => report.mov_url,
                    index: route(`${routePrefix}.index`),
                }}
                filterPrefix=""
                permissions={{
                    create: Boolean(auth[`canCreate${permissionStem}`]),
                    update: Boolean(auth[`canUpdate${permissionStem}`]),
                    delete: Boolean(auth[`canDelete${permissionStem}`]),
                }}
            />
        </div>
    </AuthenticatedLayout>;
}
