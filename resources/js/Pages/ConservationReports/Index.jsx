import { usePage } from '@inertiajs/react';
import StandardAReportSubmissionTracker from '@/Components/StandardAReportSubmissionTracker';
import PamoPambDraftWorkspace from '@/Components/PamoPambDraftWorkspace';

export default function Index({ workflow, submissions, protectedAreas, protectedAreasByOffice = {}, targetOffices = [], filters = {}, pamoDrafts = [], pamoWorkspace }) {
    const { auth = {} } = usePage().props;

    return <>
        {pamoWorkspace === true && <PamoPambDraftWorkspace workflow={workflow} drafts={pamoDrafts} />}
        {pamoWorkspace === false && <div role="status" className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">PAMO PAMB drafts are unavailable until the pilot installation migration is applied.</div>}
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
            permissions={{
                create: pamoWorkspace === undefined && Boolean(auth.canCreateTechnicalReports),
                update: pamoWorkspace === true || (pamoWorkspace === undefined && Boolean(auth.canUpdateTechnicalReports)),
                delete: pamoWorkspace === undefined && Boolean(auth.canDeleteTechnicalReports),
                pamoCorrectionOnly: pamoWorkspace === true && ['regular_pamb', 'special_pamb', 'twc_meetings'].includes(workflow.key),
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
