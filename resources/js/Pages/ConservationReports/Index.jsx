import { usePage } from '@inertiajs/react';
import StandardAReportSubmissionTracker from '@/Components/StandardAReportSubmissionTracker';

export default function Index({ workflow, submissions, protectedAreas, protectedAreasByOffice = {}, targetOffices = [], filters = {}, pamoDrafts = [], pamoWorkspace }) {
    const { auth = {} } = usePage().props;

    return <>
        <StandardAReportSubmissionTracker
            submissions={submissions}
            protectedAreas={protectedAreas}
            protectedAreasByOffice={protectedAreasByOffice}
            targetOffices={targetOffices}
            filters={filters}
            moduleLabel={workflow.label}
            historicalSource="conservation"
            description={workflow.description}
            workflowConfig={workflow}
            pamoDrafts={pamoDrafts}
            pamoWorkspace={pamoWorkspace}
            permissions={{
                create: pamoWorkspace === undefined && Boolean(auth.canCreateTechnicalReports),
                update: pamoWorkspace === true || (pamoWorkspace === undefined && Boolean(auth.canUpdateTechnicalReports)),
                delete: pamoWorkspace === undefined && Boolean(auth.canDeleteTechnicalReports),
                pamoCorrectionOnly: pamoWorkspace === true,
            }}
            submissionRoutes={{
                index: route('conservation-reports.index', workflow.key),
                store: route('conservation-reports.store', workflow.key),
                update: id => route('conservation-reports.update', [workflow.key, id]),
                destroy: id => route('conservation-reports.destroy', [workflow.key, id]),
                mov: report => report.mov_url,
            }}
        />
    </>;
}
