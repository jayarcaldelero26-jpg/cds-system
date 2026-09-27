import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportSubmissionTracker from '@/Pages/Bms/ReportSubmissionTracker';

export default function StandardAReportSubmissionTracker({ submissions, protectedAreas, protectedAreasByOffice = {}, filters = {}, moduleLabel, routePrefix, submissionRoutes = null, workflowConfig = null, targetOffices = [], description = '15-working-day submission compliance tracking.', permissions = null, dateConductedRangesEnabled = false }) {
    const { auth = {} } = usePage().props;
    const permissionStem = moduleLabel === 'BAMS' ? 'Bams' : 'Imea';

    return <AuthenticatedLayout title={`${moduleLabel} Report`}>
        <PageHeader
            title={`${moduleLabel} Report`}
            description={description}

        />
        <div className="mt-6">
            <ReportSubmissionTracker
                submissions={submissions}
                protectedAreas={protectedAreas}
                protectedAreasByOffice={protectedAreasByOffice}
                filters={filters}
                moduleLabel={moduleLabel}
                submissionRoutes={submissionRoutes || {
                    store: route(`${routePrefix}.store`),
                    update: id => route(`${routePrefix}.update`, id),
                    destroy: id => route(`${routePrefix}.destroy`, id),
                    mov: report => report.mov_url,
                    index: route(`${routePrefix}.index`),
                }}
                workflowConfig={workflowConfig || { activity_required: true, document_type_required: false, period_required: true, date_conducted_required: true, date_accomplished_required: true }}
                dateConductedRangesEnabled={dateConductedRangesEnabled}
                targetOffices={targetOffices}
                filterPrefix=""
                permissions={permissions || {
                    create: Boolean(auth[`canCreate${permissionStem}`]),
                    update: Boolean(auth[`canUpdate${permissionStem}`]),
                    delete: Boolean(auth[`canDelete${permissionStem}`]),
                }}
            />
        </div>
    </AuthenticatedLayout>;
}
