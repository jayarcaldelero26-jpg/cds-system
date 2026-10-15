import CrudTable from '@/Components/Crud/CrudTable';
import { formatManila } from '@/Utils/dateFormatters';

export default function PamoSavedDraftsTable({ drafts = [], workflow, onRowClick }) {
    const periodField = workflow?.period_field || 'reporting_period';

    return <CrudTable compact title="PAMO drafts" subtitle="Drafts stay separate from submitted reports until Forward succeeds." columns={[
        { key: 'activity', label: 'Activity', render: draft => draft.report_payload?.activity_name || workflow?.label },
        { key: 'period', label: workflow?.period_label || 'Reporting period', render: draft => draft.report_payload?.[periodField] || '—' },
        { key: 'started', label: 'Preparation started', render: draft => formatManila(draft.started_at) },
        { key: 'status', label: 'Status', render: draft => draft.status === 'forwarded' ? 'Forwarded' : 'Draft' },
    ]} rows={drafts} rowKey="id" onRowClick={draft => draft.status === 'forwarded' ? undefined : onRowClick?.(draft)} isRowDisabled={draft => draft.status === 'forwarded'} emptyTitle="No PAMO drafts" emptyDescription="Choose New draft when report preparation begins." />;
}
