import CrudSection from '@/Components/Crud/CrudSection';
import { formatReportDate, formatReportDateTime } from '@/Utils/dateFormatters';

const FALLBACK = '\u2014';

function eventDate(value) {
    if (!value) return FALLBACK;
    return String(value).length <= 10 ? formatReportDate(value, FALLBACK) : formatReportDateTime(value, FALLBACK);
}

function SummaryItem({ label, value, date = false }) {
    return <div className="min-w-0 rounded-lg border border-gray-100 bg-gray-50/80 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/50">
        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
        <p className="mt-1 break-words text-sm font-semibold text-gray-900 dark:text-white">{date ? eventDate(value) : value || FALLBACK}</p>
    </div>;
}

function Marker({ status }) {
    if (status === 'completed') return <span className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-green-700 text-[10px] font-black text-white" aria-hidden="true">✓</span>;
    if (status === 'current') return <span className="mt-1 h-3 w-3 shrink-0 rounded-full bg-green-700 ring-4 ring-green-100 dark:bg-green-400 dark:ring-green-950" aria-hidden="true" />;
    return <span className="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600" aria-hidden="true" />;
}

export default function DocumentRoutingTimeline({ row, onAction }) {
    const routing = row?.routing;
    if (!routing) return null;
    const current = (routing.timeline || []).find(event => event.status === 'current');
    const action = row.can_transition ? (routing.actions || [])[0] : null;

    return <div className="space-y-4">
        <CrudSection title="Current Processing" subtitle="Server-derived routing state. Routing status is separate from compliance status.">
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <SummaryItem label="Current Location" value={routing.current_location} />
                <SummaryItem label="Current Status" value={routing.current_status} />
                <SummaryItem label="Responsible Office" value={routing.responsible_office} />
                <SummaryItem label="Responsible User Category" value={routing.responsible_user_category} />
                <SummaryItem label="In Transit To" value={routing.in_transit_to} />
                <SummaryItem label="Pending Since" value={routing.pending_since} date />
                <SummaryItem label="Working Days Pending" value={routing.working_days_pending === null || routing.working_days_pending === undefined ? null : `${routing.working_days_pending} working day${routing.working_days_pending === 1 ? '' : 's'}`} />
                <SummaryItem label="Next Expected Action" value={routing.next_expected_action} />
                <SummaryItem label="Deadline" value={routing.deadline} date />
                <SummaryItem label="Compliance Status" value={routing.compliance_status} />
                <SummaryItem label="Last Updated" value={routing.last_updated} date />
                <SummaryItem label="Recorded By" value={routing.recorded_by} />
            </div>
            {routing.last_action && <div className="mt-3 rounded-lg border border-green-100 bg-green-50/60 px-3 py-2 text-xs dark:border-green-900/60 dark:bg-green-950/20">
                <p className="font-bold uppercase tracking-wide text-green-800 dark:text-green-300">Last Action</p>
                <p className="mt-1 font-semibold text-gray-900 dark:text-white">{routing.last_action.label} · {eventDate(routing.last_action.occurred_at)}</p>
                {routing.last_action.recorded_by && <p className="mt-0.5 text-gray-600 dark:text-gray-300">Recorded by: {routing.last_action.recorded_by}</p>}
            </div>}
            {action && <button type="button" onClick={() => onAction?.(action)} className="mt-3 rounded-lg bg-green-700 px-3 py-2 text-xs font-bold text-white hover:bg-green-800">{action.action_label || `Record ${action.label}`}</button>}
        </CrudSection>

        <CrudSection title="Document Routing" subtitle={`${routing.profile_label}. Only persisted workflow milestones are shown.`}>
            <ol className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800" aria-label="Document routing history">
                {(routing.timeline || []).map(event => <li key={event.key} className="flex items-start gap-3 px-3 py-2.5">
                    <Marker status={event.status} />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-start justify-between gap-2"><p className={`text-sm ${event.status === 'completed' || event.status === 'current' ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-500 dark:text-gray-400'}`}>{event.label}</p><span className="rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-bold uppercase text-green-800 dark:bg-green-950/50 dark:text-green-300">{event.event_type}</span></div>
                        <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{event.occurred_at ? eventDate(event.occurred_at) : event.status === 'current' ? 'Awaiting action' : 'Not yet reached'}</p>
                        <p className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">{event.from || FALLBACK} → {event.to || FALLBACK} · Office: {event.office || FALLBACK}</p>
                        {event.recorded_by && <p className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">Recorded by {event.recorded_by}{event.actor_category ? ` · ${event.actor_category}` : ''}</p>}
                    </div>
                </li>)}
            </ol>
            {routing.detailed_route_requires_confirmation && <p className="mt-2 text-[11px] text-gray-500 dark:text-gray-400">This workflow currently stores canonical release, receipt, and endorsement milestones. Additional internal stages require a confirmed business route before they can be recorded.</p>}
        </CrudSection>
    </div>;
}
