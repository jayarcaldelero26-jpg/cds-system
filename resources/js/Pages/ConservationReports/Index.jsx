import { usePage } from '@inertiajs/react';
import StandardAReportSubmissionTracker from '@/Components/StandardAReportSubmissionTracker';

export default function Index({ workflow, submissions, protectedAreas, targetOffices = [], filters = {} }) {
    const { auth = {} } = usePage().props;

    return <StandardAReportSubmissionTracker
        submissions={submissions}
        protectedAreas={protectedAreas}
        targetOffices={targetOffices}
        filters={filters}
        moduleLabel={workflow.label}
        description={workflow.description}
        workflowConfig={workflow}
        permissions={{
            create: Boolean(auth.canCreateTechnicalReports),
            update: Boolean(auth.canUpdateTechnicalReports),
            delete: Boolean(auth.canDeleteTechnicalReports),
        }}
        submissionRoutes={{
            index: route('conservation-reports.index', workflow.key),
            store: route('conservation-reports.store', workflow.key),
            update: id => route('conservation-reports.update', [workflow.key, id]),
            destroy: id => route('conservation-reports.destroy', [workflow.key, id]),
            mov: report => report.mov_url,
        }}
    />;
}