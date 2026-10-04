import CrudSection from "@/Components/Crud/CrudSection";
import RoutingAttachmentLink from "@/Components/SubmissionTracking/RoutingAttachmentLink";
import { formatReportDate, formatReportDateTime } from "@/Utils/dateFormatters";

const FALLBACK = "\u2014";

export default function SubmissionReviewHistory({ row }) {
    const mov = row?.mov_processing?.applicable ? row.mov_processing : null;
    const movEvents = mov?.review_history || [];
    const routingEvents = row?.routing?.routing_history || [];

    return <div className="space-y-4">
        {mov && <CrudSection title="MOV Review History">
            {row.date_conducted && <p className="text-xs text-gray-600 dark:text-gray-300">Activity Conducted · Report date: {formatReportDate(row.date_conducted, FALLBACK)}</p>}
            {movEvents.length ? <ol className="mt-2 space-y-2">
                {movEvents.map((event, index) => <li key={`${event.event_key || event.event_label}-${index}`} className="rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <p className="font-semibold text-gray-900 dark:text-white">{event.event_label || "MOV review event"}</p>
                    <p className="mt-1 text-gray-600 dark:text-gray-300">{event.recorded_by || "Recorded user"}{event.recorded_role || event.recorded_office ? ` · ${[event.recorded_role, event.recorded_office].filter(Boolean).join(" · ")}` : ""} · {event.recorded_at ? formatReportDateTime(event.recorded_at, FALLBACK) : FALLBACK}</p>
                    {event.remarks && <p className="mt-1 whitespace-pre-wrap text-gray-700 dark:text-gray-200">{event.remarks}</p>}
                </li>)}
            </ol> : !row.date_conducted && <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">No MOV review events recorded.</p>}
        </CrudSection>}

        {routingEvents.length > 0 && <CrudSection title="Routing Corrections and Overrides">
            <ol className="space-y-2">
                {routingEvents.map((event) => <li key={event.key} className="rounded-lg border border-gray-200 bg-white p-3 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <p className="font-semibold text-gray-900 dark:text-white">
                        {event.administrative_override && <span className="mr-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">Admin Override</span>}
                        {event.label}
                    </p>
                    {event.administrative_override && (event.override_for_category || event.override_for_office) && <p className="mt-1 text-gray-600 dark:text-gray-300">Override for: {[event.override_for_category, event.override_for_office].filter(Boolean).join(" Â· ")}</p>}
                    <p className="mt-1 text-gray-600 dark:text-gray-300">{event.occurred_at ? formatReportDateTime(event.occurred_at, FALLBACK) : FALLBACK}{event.recorded_by ? ` · ${event.recorded_by}` : ""}{event.actor_category ? ` · ${event.actor_category}` : ""}{event.actor_office ? ` · ${event.actor_office}` : ""}{event.remarks ? ` · ${event.remarks}` : ""}</p>
                    {event.correction && <p className="mt-1 text-gray-600 dark:text-gray-300">By: {event.from || "Records office"} · Reason: {event.correction_reason || "Correction required"}{event.correction_detail ? ` · Remarks: ${event.correction_detail}` : ""} · Returned To: {event.to || "Previous accountable sender"}</p>}
                    <RoutingAttachmentLink attachment={event.attachment} />
                </li>)}
            </ol>
        </CrudSection>}

        {!mov && routingEvents.length === 0 && <p className="text-sm text-gray-600 dark:text-gray-300">No review or routing correction history is available for this record.</p>}
    </div>;
}
