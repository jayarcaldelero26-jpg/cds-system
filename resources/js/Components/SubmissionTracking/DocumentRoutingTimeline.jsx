import { useState } from "react";
import { standardActionLabel } from "@/Utils/routingLabels";
import CrudSection from "@/Components/Crud/CrudSection";
import { formatReportDate, formatReportDateTime } from "@/Utils/dateFormatters";
import RoutingAttachmentLink from "@/Components/SubmissionTracking/RoutingAttachmentLink";
import { timelinePresentation } from "@/Utils/submissionTrackingPresentation";
import Button from "@/Components/Button";

const FALLBACK = "\u2014";

function eventDate(value) {
    if (!value) return FALLBACK;
    return String(value).length <= 10
        ? formatReportDate(value, FALLBACK)
        : formatReportDateTime(value, FALLBACK);
}

function SummaryItem({ label, value, date = false }) {
    if (value === null || value === undefined || String(value).trim() === "")
        return null;
    return (
        <div className="min-w-0 rounded-lg border border-gray-100 bg-gray-50/80 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/50">
            <p className="text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {label}
            </p>
            <p className="mt-1 break-words text-sm font-medium text-gray-900 dark:text-white">
                {date ? eventDate(value) : value}
            </p>
        </div>
    );
}

function Marker({ status }) {
    if (status === "completed")
        return (
            <span
                className="mt-1 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-green-700 text-[10px] font-black text-white"
                aria-hidden="true"
            >
                ✓
            </span>
        );
    if (status === "current")
        return (
            <span
                className="mt-1 h-3 w-3 shrink-0 rounded-full bg-green-700 ring-4 ring-green-100 dark:bg-green-400 dark:ring-green-950"
                aria-hidden="true"
            />
        );
    return (
        <span
            className="mt-1 h-3 w-3 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600"
            aria-hidden="true"
        />
    );
}

export default function DocumentRoutingTimeline({ row, onAction, expandAll, onExpandAllChange, hideRoutingHistory = false }) {
    const [localExpanded, setLocalExpanded] = useState(false);
    const showRemainingSteps = expandAll ?? localExpanded;
    const setShowRemainingSteps = onExpandAllChange ?? setLocalExpanded;
    const routing = row?.routing;
    if (!routing) return null;
    const actions = row.can_transition ? routing.actions || [] : [];
    const timeline = routing.timeline || [];
    const { hiddenSteps, visibleSteps: visibleTimeline, hasToggle } =
        timelinePresentation(timeline, showRemainingSteps);

    return (
        <div className="space-y-4">
            <CrudSection
                title="Routing Activity"
                cardSurface
                subtitle="Recent activity and operational timing. Current holder and routing status are summarized above."
            >
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryItem
                        label="In Transit To"
                        value={routing.in_transit_to}
                    />
                    <SummaryItem
                        label="Last Updated"
                        value={routing.last_updated}
                        date
                    />
                    <SummaryItem
                        label="Recorded By"
                        value={routing.recorded_by}
                    />
                </div>
                {routing.last_action && (
                    <div className="mt-3 rounded-lg border border-green-100 bg-green-50/60 px-3 py-2 text-xs dark:border-green-900/60 dark:bg-green-950/20">
                        <p className="font-bold uppercase tracking-wide text-green-800 dark:text-green-300">
                            Last Action
                        </p>
                        <p className="mt-1 font-semibold text-gray-900 dark:text-white">
                            {routing.last_action.label} ·{" "}
                            {eventDate(routing.last_action.occurred_at)}
                        </p>
                        {routing.last_action.recorded_by && (
                            <p className="mt-0.5 text-gray-600 dark:text-gray-300">
                                Recorded by: {routing.last_action.recorded_by}
                            </p>
                        )}
                    </div>
                )}
                {actions.length > 0 && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        {actions.map((action) => (
                            <Button
                                size="compact"
                                variant={action.correction ? "warning" : "primary"}
                                key={action.key}
                                type="button"
                                onClick={() => onAction?.(action)}
                                className="rounded-lg px-3 py-2 text-xs"
                            >
                                {standardActionLabel(
                                    action.action_label || action.label,
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </CrudSection>

            <CrudSection
                title="Canonical Routing Progress"
                cardSurface
                subtitle={`${routing.profile_label}. Canonical routing path showing completed, current, and upcoming stages.`}
            >
                {hasToggle && <div className="mb-2 flex justify-end"><button type="button" aria-expanded={showRemainingSteps} aria-controls="document-routing-timeline-stages" onClick={() => setShowRemainingSteps((shown) => !shown)} className="rounded-md px-2 py-1 text-xs font-semibold text-green-800 outline-none hover:bg-green-50 focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-800 dark:text-green-200 dark:hover:bg-green-950/50">{showRemainingSteps ? "Show fewer steps" : `Show ${hiddenSteps.length} more steps`}</button></div>}
                <ol
                    id="document-routing-timeline-stages"
                    className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800"
                    aria-label="Document routing history"
                >
                    {visibleTimeline.map((event) => {
                        const displayStatus = event.display_status || event.status;
                        return <li
                            key={event.key}
                            className="flex items-start gap-3 px-3 py-2.5"
                        >
                            <Marker status={displayStatus} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p
                                        className={`text-sm ${displayStatus === "completed" || displayStatus === "current" ? "font-semibold text-gray-900 dark:text-white" : "font-medium text-gray-500 dark:text-gray-400"}`}
                                    >
                                        {event.label}
                                    </p>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${displayStatus === "completed" ? "bg-green-50 text-green-800 dark:bg-green-950/50 dark:text-green-300" : displayStatus === "current" ? "bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300" : "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"}`}
                                    >
                                        {event.display_status_label || (displayStatus === "completed"
                                            ? String(
                                                  event.event_type ||
                                                      "completed",
                                              ).replaceAll("_", " ")
                                            : displayStatus === "current"
                                              ? "Current"
                                              : "Pending")}
                                    </span>
                                </div>
                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {event.occurred_at
                                        ? eventDate(event.occurred_at)
                                        : displayStatus === "completed"
                                          ? "Completed"
                                          : displayStatus === "current"
                                            ? "Current checkpoint; next action shown above"
                                            : "Not yet reached"}
                                </p>
                                {displayStatus !== "completed" && displayStatus !== "current" &&
                                    event.action_label && (
                                        <p className="mt-0.5 text-[11px] font-medium text-gray-600 dark:text-gray-300">
                                            Expected action:{" "}
                                            {event.action_label}
                                        </p>
                                    )}
                                {(event.from || event.to || event.office) && (
                                    <p className="mt-0.5 break-words text-[11px] text-gray-500 dark:text-gray-400">
                                        {event.from && <>From: {event.from}</>}
                                        {event.from && event.to && " → "}
                                        {event.to && <>To: {event.to}</>}
                                        {event.office && (
                                            <> · Office: {event.office}</>
                                        )}
                                    </p>
                                )}
                                <RoutingAttachmentLink attachment={event.attachment} />
                        {event.recorded_by && (
                                    <p className="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                        Recorded by {event.recorded_by}
                                        {event.actor_category
                                            ? ` · ${event.actor_category}`
                                            : ""}
                                        {event.actor_office
                                            ? ` · ${event.actor_office}`
                                            : ""}
                                    </p>
                                )}
                            </div>
                        </li>;
                    })}
                </ol>
                {!hideRoutingHistory && (routing.routing_history || []).length > 0 && (
                    <div id="document-routing-history" className="scroll-mt-4 mt-3 rounded-xl border border-amber-100 bg-amber-50/50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <p className="text-[10px] font-bold uppercase tracking-wide text-amber-800 dark:text-amber-300">
                            Complete Routing / Correction History
                        </p>
                        <ol className="mt-2 space-y-2">
                            {routing.routing_history.map((event) => (
                                <li key={event.key} className="text-xs">
                                    <p className="font-semibold text-gray-900 dark:text-white">
                                        {event.administrative_override && (
                                            <span className="mr-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-extrabold uppercase text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">
                                                Admin Override
                                            </span>
                                        )}
                                        {event.label}
                                    </p>
                                    <p className="text-gray-600 dark:text-gray-300">
                                        {event.occurred_at &&
                                            eventDate(event.occurred_at)}
                                        {event.occurred_at &&
                                            event.recorded_by &&
                                            " · "}
                                        {event.recorded_by && event.recorded_by}
                                        {event.remarks && " · " + event.remarks}
                                    </p>
                                    {event.correction && <p className="mt-1 text-gray-600 dark:text-gray-300">
                                        By: {event.from || "Records office"} · Reason: {event.correction_reason || "Correction required"}{event.correction_detail ? ` · Remarks: ${event.correction_detail}` : ""} · Returned To: {event.to || "Previous accountable sender"}
                                    </p>}
                                    <RoutingAttachmentLink attachment={event.attachment} />
                                </li>
                            ))}
                        </ol>
                    </div>
                )}{" "}
                {routing.detailed_route_requires_confirmation && (
                    <p className="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                        This workflow currently stores canonical release,
                        receipt, and endorsement milestones. Additional internal
                        stages require a confirmed business route before they
                        can be recorded.
                    </p>
                )}
            </CrudSection>
        </div>
    );
}
