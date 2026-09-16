import {
    compactStatusForAction,
    standardActionLabel,
} from "@/Utils/routingLabels";
import {
    FloatingInput,
    FloatingSelect,
    FloatingTextarea,
} from "@/Components/Form";
import CrudFormModal from "@/Components/Crud/CrudFormModal";
import CrudDetailsModal from "@/Components/Crud/CrudDetailsModal";
import CrudSection from "@/Components/Crud/CrudSection";
import CrudSummaryGrid from "@/Components/Crud/CrudSummaryGrid";
import CrudTable from "@/Components/Crud/CrudTable";
import PageHeader from "@/Components/PageHeader";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import PambRoutingTimeline from "@/Components/SubmissionTracking/PambRoutingTimeline";
import PambMovProgress from "@/Components/SubmissionTracking/PambMovProgress";
import DocumentRoutingTimeline from "@/Components/SubmissionTracking/DocumentRoutingTimeline";
import DocumentPreviewDialog from "@/Components/SubmissionTracking/DocumentPreviewDialog";
import RoutingAttachmentField from "@/Components/SubmissionTracking/RoutingAttachmentField";
import PremiumTimePicker from "@/Components/PremiumTimePicker";
import { Link, router, useForm, usePage } from "@inertiajs/react";
import { startAuthentication } from "@simplewebauthn/browser";
import TimelinessBadge, {
    isTimelinessValue,
} from "@/Components/TimelinessBadge";
import { localDateInputValue } from "@/Utils/dateInput";
import DatePicker from "@/Components/DatePicker";
import { formatReportDate, formatReportDateTime } from "@/Utils/dateFormatters";
import { localDateTimeInputValue } from "@/Utils/timePicker";
import { useEffect, useMemo, useState } from "react";

const operationalViewDescriptions = {
    incoming: "Documents currently requiring action from your office.",
    outgoing: "Documents your office has acted on and forwarded to the next office.",
    history: "Completed routing records within your authorized scope.",
};
const incomingActionLabels = {
    receive: "Receive",
    forward: "Forward",
    release: "Release",
    decision: "Review / Decision",
    correction: "For Correction",
};
const monitoringViewDescriptions = {
    incoming:
        "Active submissions currently owned by their respective accountable offices and categories.",
    outgoing:
        "Active routing handoffs currently owned by their respective accountable offices and categories.",
    history:
        "Completed routing records across your authorized monitoring scope.",
};
const FALLBACK = "\u2014";
const plainDate = (value) => (value ? formatReportDate(value, "") : null);
const badgeTone = (value) => {
    const normalized = String(value || "").toLowerCase();
    if (
        normalized.includes("correction") ||
        normalized.includes("reject") ||
        normalized.includes("failed")
    )
        return "bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-200 dark:ring-red-900";
    if (normalized.includes("regional") || normalized.includes("endorsement"))
        return "bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-200 dark:ring-indigo-900";
    if (
        normalized.includes("pending") ||
        normalized.includes("awaiting") ||
        normalized.includes("overdue") ||
        normalized.includes("due")
    )
        return "bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900";
    if (
        normalized.includes("ready") ||
        normalized.includes("received") ||
        normalized.includes("complete") ||
        normalized.includes("submitted") ||
        normalized.includes("released")
    )
        return "bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-900";
    return "bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700";
};
const Badge = ({ value }) =>
    isTimelinessValue(value) ? (
        <TimelinessBadge value={value} />
    ) : (
        <span
            className={
                "inline-flex rounded-full px-2.5 py-1 text-xs font-bold ring-1 ring-inset " +
                badgeTone(value)
            }
        >
            {value}
        </span>
    );
const historyWidths = {
    100: "w-[100px] min-w-[100px]",
    120: "w-[120px] min-w-[120px]",
    135: "w-[135px] min-w-[135px]",
    150: "w-[150px] min-w-[150px]",
    160: "w-[160px] min-w-[160px]",
    180: "w-[180px] min-w-[180px]",
    190: "w-[190px] min-w-[190px]",
    220: "w-[220px] min-w-[220px]",
};
const historyHeader = (width) =>
    `${historyWidths[width]} px-4 py-3 align-middle text-xs font-semibold leading-4`;
const historyCell = (width) =>
    `${historyWidths[width]} px-4 py-3 align-top text-sm leading-5`;
const routingFor = (row) => row?.routing || row?.routing_summary || {};
const routingStatusFor = (row) => {
    const routing = routingFor(row);
    if (
        routing.in_transit_to &&
        !/awaiting receipt|received/i.test(String(routing.current_status || ""))
    )
        return "Awaiting Receipt by " + routing.in_transit_to;
    return (
        routing.current_status ||
        row?.current_processing_status ||
        row?.submission_status ||
        null
    );
};
const responsibleOfficeFor = (row) =>
    routingFor(row).responsible_office || row?.target_office || null;
const responsibleCategoryFor = (row) =>
    routingFor(row).responsible_user_category || null;
const nextActionFor = (row) => routingFor(row).next_expected_action || null;
const compactRoutingStatusFor = (row) => {
    const status = routingStatusFor(row);
    const receipt = status.match(/^Awaiting Receipt by (.+)$/i);
    if (receipt)
        return "Awaiting " + receipt[1].replace(/\s+Unit$/i, "") + " Receipt";
    const review = status.match(/^Under Review by (.+)$/i);
    if (review) return "Under " + review[1] + " Review";
    return status;
};
const compactProgressFor = (row) => {
    const routing = routingFor(row);
    const timeline =
        (row?.pamb_routing_applicable
            ? row.routing_timeline
            : routing.timeline) || [];
    const currentIndex = timeline.findIndex(
        (item) =>
            item?.status === "current" || item?.key === routing.current_stage,
    );
    const start =
        currentIndex < 0
            ? Math.max(0, timeline.length - 4)
            : Math.max(0, currentIndex - 1);
    const end =
        currentIndex < 0
            ? timeline.length
            : Math.min(timeline.length, currentIndex + 3);
    return timeline.slice(start, end).map((item) => ({
        ...item,
        compactLabel:
            item.status === "current" && routing.in_transit_to
                ? "Awaiting " +
                  routing.in_transit_to.replace(/\s+Unit$/i, "") +
                  " Receipt"
                : item.compactLabel ||
                  item.label ||
                  item.stage_label ||
                  item.name ||
                  null,
    }));
};

const currentActionFor = (row) =>
    routingFor(row).actions?.[0]?.action_label || nextActionFor(row);
const availableActionsFor = (row) =>
    (routingFor(row).actions || [])
        .map((action) => standardActionLabel(action.action_label || action.label))
        .filter((label, index, labels) => label && labels.indexOf(label) === index);
const requiredActionFor = (row) =>
    availableActionsFor(row).join(" / ") || standardActionLabel(currentActionFor(row));
const compactStatusFor = (row) => {
    if (row?.routing_complete) return "Completed";
    const actionStatus = compactStatusForAction(currentActionFor(row));
    if (actionStatus) return actionStatus;
    const status = String(routingStatusFor(row) || "");
    if (/returned|correction/i.test(status)) return "Returned for Correction";
    if (/receipt|awaiting.*receive|for receipt/i.test(status))
        return "For Receipt";
    if (/review/i.test(status)) return "For Review";
    if (/release/i.test(status)) return "For Release";
    if (/approval|approve/i.test(status)) return "For Approval";
    if (/forward|transit/i.test(status)) return "For Forwarding";
    if (/complete|regional/i.test(status)) return "Completed";
    return status || "In Progress";
};
const lastActionFor = (row) => routingFor(row).last_action?.label || "—";
const actionDateFor = (row) =>
    routingFor(row).last_action?.occurred_at ||
    routingFor(row).last_updated ||
    row.completed_at ||
    null;
const formatActionDate = (value) =>
    !value
        ? "—"
        : String(value).length <= 10
          ? plainDate(value)
          : formatReportDateTime(value, FALLBACK);
const SubmissionDetailsPanel = ({ row, onViewFullDetails, onAction }) => {
    if (!row)
        return (
            <aside className="rounded-xl border border-dashed border-gray-300 bg-white p-5 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                <p className="font-semibold text-gray-700 dark:text-gray-200">
                    No submission selected
                </p>
                <p className="mt-1 text-xs">
                    There are no records available in this queue.
                </p>
            </aside>
        );
    const routing = routingFor(row);
    const timeline =
        (row.pamb_routing_applicable
            ? row.routing_timeline
            : routing.timeline) || [];
    const progress = compactProgressFor(row);
    return (
        <aside className="flex min-h-[420px] flex-col rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div className="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <p className="text-sm font-extrabold text-gray-900 dark:text-white">
                    Submission Details
                </p>
                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Current record context and routing state
                </p>
            </div>
            <div className="space-y-4 p-4 text-xs">
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                    <div>
                        <p className="text-[10px] font-semibold text-gray-500">
                            Protected Area
                        </p>
                        <p className="mt-0.5 font-semibold text-gray-900 dark:text-white">
                            {row.protected_area || null}
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Office
                        </p>
                        <p className="mt-0.5 font-semibold text-gray-900 dark:text-white">
                            {responsibleOfficeFor(row)}
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Module
                        </p>
                        <p className="mt-0.5 text-gray-700 dark:text-gray-200">
                            {row.module || null}
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Report / Activity
                        </p>
                        <p className="mt-0.5 text-gray-700 dark:text-gray-200">
                            {row.activity_name ||
                                row.document_type ||
                                row.report_type ||
                                null}
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Reporting Period
                        </p>
                        <p className="mt-0.5 text-gray-700 dark:text-gray-200">
                            {row.reporting_period || null}
                        </p>
                    </div>
                </div>
                <div className="rounded-lg border border-green-200 bg-green-50/60 p-3 dark:border-green-900 dark:bg-green-950/20">
                    <p className="text-[10px] font-bold uppercase tracking-wide text-green-800 dark:text-green-300">
                        Routing Status
                    </p>
                    <p className="mt-1 text-sm font-extrabold text-green-900 dark:text-green-100">
                        {routingStatusFor(row)}
                    </p>
                </div>
                {row.can_transition && availableActionsFor(row).length > 0 && (
                    <div className="rounded-lg border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-700 dark:bg-gray-900/50">
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Available Actions
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {(routing.actions || []).map((action) => (
                                <button
                                    key={action.key}
                                    type="button"
                                    onClick={() => onAction?.(action)}
                                    className={
                                        action.correction
                                            ? "rounded-lg bg-amber-700 px-3 py-2 text-xs font-bold text-white hover:bg-amber-800"
                                            : "rounded-lg bg-green-700 px-3 py-2 text-xs font-bold text-white hover:bg-green-800"
                                    }
                                >
                                    {standardActionLabel(action.action_label || action.label)}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <div className="grid gap-x-3 gap-y-2 sm:grid-cols-2 xl:grid-cols-1">
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Deadline
                        </p>
                        <p className="mt-0.5 text-gray-800 dark:text-gray-200">
                            {plainDate(row.deadline_submission)}
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Compliance
                        </p>
                        <p className="mt-0.5">
                            <Badge
                                value={
                                    routing.compliance_status || row.timeliness
                                }
                            />
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Responsible Category
                        </p>
                        <p className="mt-0.5 text-gray-800 dark:text-gray-200">
                            {responsibleCategoryFor(row)}
                        </p>
                    </div>
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-wide text-gray-500">
                            Next Expected Action
                        </p>
                        <p className="mt-0.5 text-gray-800 dark:text-gray-200">
                            {nextActionFor(row)}
                        </p>
                    </div>
                </div>
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <p className="text-xs font-extrabold text-gray-900 dark:text-white">
                            Routing Progress
                        </p>
                        <span className="text-[10px] text-gray-500">
                            Summary
                        </span>
                    </div>
                    <div className="space-y-2">
                        {progress.length ? (
                            progress.map((item, index) => (
                                <div
                                    key={
                                        String(
                                            item.key ||
                                                item.stage_key ||
                                                item.label ||
                                                item.name,
                                        ) + index
                                    }
                                    className="flex gap-2"
                                >
                                    <span
                                        className={
                                            "mt-0.5 h-2 w-2 shrink-0 rounded-full " +
                                            (item.status === "completed"
                                                ? "bg-green-600"
                                                : item.status === "current"
                                                  ? "bg-green-600 ring-4 ring-green-100 dark:ring-green-950"
                                                  : "bg-gray-300 dark:bg-gray-600")
                                        }
                                    />
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold text-gray-800 dark:text-gray-200">
                                            {item.compactLabel ||
                                                item.label ||
                                                item.stage_label ||
                                                item.name ||
                                                null}
                                        </p>
                                        <p className="text-[11px] text-gray-500">
                                            {item.occurred_at
                                                ? formatReportDateTime(
                                                      item.occurred_at,
                                                      FALLBACK,
                                                  )
                                                : item.status || "Pending"}
                                        </p>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <p className="text-xs text-gray-500">
                                Routing progress will appear as events are
                                recorded.
                            </p>
                        )}
                    </div>
                </div>
            </div>
            <div className="mt-auto border-t border-gray-200 p-4 dark:border-gray-800">
                <button
                    type="button"
                    onClick={onViewFullDetails}
                    className="inline-flex w-full items-center justify-center rounded-lg bg-green-700 px-3 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-green-800"
                >
                    View Full Details
                </button>
            </div>
        </aside>
    );
};

export default function Index({
    queues = {},
    workspaceQueues = {},
    filters = {},
    filterOptions = {},
    trackingContext = {},
    pagination = {},
}) {
    const { props: pageProps } = usePage();
    const canCorrectSubmissionRouting = Boolean(
        pageProps.auth?.canCorrectSubmissionRouting,
    );
    const canAdminRoutingOverride = Boolean(
        pageProps.auth?.canAdminRoutingOverride,
    );
    const isGlobalMonitoring = Boolean(trackingContext.is_global_user);
    const [tab, setTab] = useState(trackingContext.view || "incoming");
    const [search, setSearch] = useState(filters.search || "");
    const [module, setModule] = useState(filters.module || "");
    const [protectedAreaId, setProtectedAreaId] = useState(
        filters.protected_area_id || "",
    );
    const [status, setStatus] = useState(filters.status || "");
    const [selected, setSelected] = useState(null);
    const [incomingActionTab, setIncomingActionTab] = useState(null);
    const [details, setDetails] = useState(
        trackingContext.selected_record || null,
    );
    const [showFullDetails, setShowFullDetails] = useState(false);
    const form = useForm({
        date: localDateInputValue(),
        stage: "",
        remarks: "",
        correction_reason_key: "",
        correction_detail: "",
        attachment: null,
    });
    const internalForm = useForm({ remarks: "", stage: "", attachment: null });
    const correctionForm = useForm({
        dates: {},
        release_events: {},
        internal_events: {},
        reason: "",
        password: "",
    });
    const reviewForm = useForm({ decision: "", remarks: "" });
    const [correction, setCorrection] = useState(null);
    const [routingStage, setRoutingStage] = useState(null);
    const [previewRow, setPreviewRow] = useState(null);
    const [override, setOverride] = useState(null);
    const [overrideReason, setOverrideReason] = useState("");
    const [overrideAction, setOverrideAction] = useState("");
    const [overrideProcessing, setOverrideProcessing] = useState(false);
    const [overrideError, setOverrideError] = useState("");
    const [reviewing, setReviewing] = useState(null);
    const openAdminOverride = async (row) => {
        setOverrideError("");
        setOverrideProcessing(true);
        try {
            const response = await fetch(
                route("submission-tracking.admin-override.options", [
                    row.source,
                    row.source_id,
                ]),
                {
                    headers: { Accept: "application/json" },
                    credentials: "same-origin",
                },
            );
            const payload = await response.json();
            if (!response.ok)
                throw new Error(
                    payload.message ||
                        "Register a passkey before using Administrative Override.",
                );
            setOverride({ row, ...payload.override, options: payload.options });
            setOverrideAction(payload.override.actions?.[0]?.key || "");
            setOverrideReason("");
        } catch (error) {
            setOverrideError(
                error.message || "Unable to start administrative override.",
            );
        } finally {
            setOverrideProcessing(false);
        }
    };
    const submitAdminOverride = async (event) => {
        event.preventDefault();
        if (!overrideAction || !overrideReason.trim()) {
            setOverrideError(
                "Choose a valid action and provide a mandatory reason.",
            );
            return;
        }
        setOverrideProcessing(true);
        setOverrideError("");
        try {
            const credential = await startAuthentication({
                optionsJSON: override.options,
            });
            const response = await fetch(
                route("submission-tracking.admin-override.execute", [
                    override.row.source,
                    override.row.source_id,
                ]),
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-CSRF-TOKEN":
                            document.querySelector('meta[name="csrf-token"]')
                                ?.content || "",
                    },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        action: overrideAction,
                        reason: overrideReason,
                        credential,
                    }),
                },
            );
            const payload = await response.json();
            if (!response.ok)
                throw new Error(
                    payload.message ||
                        Object.values(payload.errors || {})
                            .flat()
                            .join(" ") ||
                        "Administrative override was rejected.",
                );
            setOverride(null);
            setDetails(null);
            router.reload({
                only: [
                    "queues",
                    "workspaceQueues",
                    "trackingContext",
                    "pagination",
                ],
            });
        } catch (error) {
            setOverrideError(
                error.message ||
                    "Passkey verification failed. No routing action was recorded.",
            );
        } finally {
            setOverrideProcessing(false);
        }
    };
    const incomingRows = workspaceQueues.incoming || [];
    const incomingActionTabs = useMemo(() => {
        if (isGlobalMonitoring) return [];
        return Object.keys(incomingActionLabels).filter((category) =>
            incomingRows.some((row) => row.incoming_action_category === category),
        );
    }, [incomingRows, isGlobalMonitoring]);
    const queueRows = workspaceQueues[tab] || [];
    const rows = tab === "incoming" && incomingActionTab
        ? queueRows.filter((row) => row.incoming_action_category === incomingActionTab)
        : queueRows;
    const action = [
        "Action",
        "Record routing action",
        "Date of the real-world routing action",
        "Save Action",
    ];
    const genericAction = selected?.routing?.actions?.find(
        (item) =>
            item.key === form.data.stage ||
            (form.data.stage === "penro_receipt" &&
                item.key === "receive_at_penro_records"),
    );
    const selectedActionLabel = standardActionLabel(
        genericAction?.action_label || form.data.stage || action[0],
    );
    const columns = useMemo(
        () =>
            tab === "history"
                ? [
                      {
                          key: "submission",
                          label: "Submission",
                          headerClassName: "min-w-[150px]",
                          cellClassName: "min-w-[150px]",
                          render: (row) => (
                              <div className="min-w-0">
                                  <p className="truncate font-semibold text-gray-900 dark:text-white">
                                      {row.module ||
                                          row.document_type ||
                                          "Submission"}
                                  </p>
                                  <p className="mt-0.5 text-[11px] text-gray-500">
                                      {row.source
                                          ? row.source + " #" + row.source_id
                                          : null}
                                  </p>
                              </div>
                          ),
                      },
                      {
                          key: "office_area",
                          label: "Office / Protected Area",
                          headerClassName: "min-w-[190px]",
                          cellClassName: "min-w-[190px]",
                          render: (row) => (
                              <div className="min-w-0">
                                  <p className="break-words font-semibold text-gray-900 dark:text-white">
                                      {row.protected_area || "—"}
                                  </p>
                                  <p className="mt-0.5 break-words text-[11px] text-gray-500">
                                      {responsibleOfficeFor(row) ||
                                          row.target_office ||
                                          "—"}
                                  </p>
                              </div>
                          ),
                      },
                      {
                          key: "activity",
                          label: "Report / Activity",
                          headerClassName: "min-w-[180px]",
                          cellClassName: "min-w-[180px]",
                          render: (row) => (
                              <div className="min-w-0">
                                  <p className="break-words font-semibold text-gray-800 dark:text-gray-100">
                                      {row.activity_name ||
                                          row.document_type ||
                                          row.report_type ||
                                          "—"}
                                  </p>
                                  <p className="mt-0.5 text-[11px] text-gray-500">
                                      {row.reporting_period || "—"}
                                  </p>
                              </div>
                          ),
                      },
                      {
                          key: "completed_action",
                          label: "Completed Action",
                          headerClassName: "min-w-[135px]",
                          cellClassName: "min-w-[135px]",
                          render: (row) => (
                              <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                  {standardActionLabel(lastActionFor(row))}
                              </span>
                          ),
                      },
                      {
                          key: "completed_date",
                          label: "Completed Date",
                          headerClassName: "min-w-[165px]",
                          cellClassName: "min-w-[165px]",
                          render: (row) => (
                              <span className="text-xs text-gray-700 dark:text-gray-200">
                                  {formatActionDate(actionDateFor(row))}
                              </span>
                          ),
                      },
                      {
                          key: "destination",
                          label: "Final Destination",
                          headerClassName: "min-w-[165px]",
                          cellClassName: "min-w-[165px]",
                          render: (row) => (
                              <span className="break-words text-xs text-gray-700 dark:text-gray-200">
                                  {routingFor(row).current_location ||
                                      routingFor(row).responsible_office ||
                                      row.target_office ||
                                      "—"}
                              </span>
                          ),
                      },
                      {
                          key: "status",
                          label: "Status",
                          headerClassName: "min-w-[120px]",
                          cellClassName: "min-w-[120px]",
                          render: (row) => <Badge value="Completed" />,
                      },
                      {
                          key: "open",
                          label: "Action",
                          headerClassName: "min-w-[90px]",
                          cellClassName: "min-w-[90px]",
                          render: () => (
                              <span className="text-xs font-bold text-green-700 dark:text-green-300">
                                  View Details
                              </span>
                          ),
                      },
                  ]
                : tab === "outgoing"
                  ? [
                        {
                            key: "submission",
                            label: "Submission",
                            headerClassName: "min-w-[150px]",
                            cellClassName: "min-w-[150px]",
                            render: (row) => (
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-gray-900 dark:text-white">
                                        {row.module ||
                                            row.document_type ||
                                            "Submission"}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-gray-500">
                                        {row.source
                                            ? `${row.source} #${row.source_id}`
                                            : null}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: "office_area",
                            label: "Office / Protected Area",
                            headerClassName: "min-w-[190px]",
                            cellClassName: "min-w-[190px]",
                            render: (row) => (
                                <div className="min-w-0">
                                    <p className="break-words font-semibold text-gray-900 dark:text-white">
                                        {row.protected_area || "—"}
                                    </p>
                                    <p className="mt-0.5 break-words text-[11px] text-gray-500">
                                        {responsibleOfficeFor(row) ||
                                            row.target_office ||
                                            "—"}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: "activity",
                            label: "Report / Activity",
                            headerClassName: "min-w-[180px]",
                            cellClassName: "min-w-[180px]",
                            render: (row) => (
                                <div className="min-w-0">
                                    <p className="break-words font-semibold text-gray-800 dark:text-gray-100">
                                        {row.activity_name ||
                                            row.document_type ||
                                            row.report_type ||
                                            "—"}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-gray-500">
                                        {row.reporting_period || "—"}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: "last_action",
                            label: "Last Action",
                            headerClassName: "min-w-[120px]",
                            cellClassName: "min-w-[120px]",
                            render: (row) => (
                                <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                    {standardActionLabel(lastActionFor(row))}
                                </span>
                            ),
                        },
                        {
                            key: "action_date",
                            label: "Sent / Action Date",
                            headerClassName: "min-w-[165px]",
                            cellClassName: "min-w-[165px]",
                            render: (row) => (
                                <span className="text-xs text-gray-700 dark:text-gray-200">
                                    {formatActionDate(actionDateFor(row))}
                                </span>
                            ),
                        },
                        {
                            key: "destination",
                            label: "Current Destination",
                            headerClassName: "min-w-[165px]",
                            cellClassName: "min-w-[165px]",
                            render: (row) => (
                                <span className="break-words text-xs text-gray-700 dark:text-gray-200">
                                    {routingFor(row).current_location ||
                                        routingFor(row).responsible_office ||
                                        row.target_office ||
                                        "—"}
                                </span>
                            ),
                        },
                        {
                            key: "status",
                            label: "Current Status",
                            headerClassName: "min-w-[130px]",
                            cellClassName: "min-w-[130px]",
                            render: (row) => (
                                <Badge value={compactStatusFor(row)} />
                            ),
                        },
                        {
                            key: "open",
                            label: "Action",
                            headerClassName: "min-w-[70px]",
                            cellClassName: "min-w-[70px]",
                            render: () => (
                                <span className="text-xs font-bold text-green-700 dark:text-green-300">
                                    View
                                </span>
                            ),
                        },
                    ]
                  : [
                        {
                            key: "submission",
                            label: "Submission",
                            headerClassName: "min-w-[150px]",
                            cellClassName: "min-w-[150px]",
                            render: (row) => (
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-gray-900 dark:text-white">
                                        {row.module ||
                                            row.document_type ||
                                            "Submission"}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-gray-500">
                                        {row.source
                                            ? `${row.source} #${row.source_id}`
                                            : null}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: "office_area",
                            label: "Office / Protected Area",
                            headerClassName: "min-w-[190px]",
                            cellClassName: "min-w-[190px]",
                            render: (row) => (
                                <div className="min-w-0">
                                    <p className="break-words font-semibold text-gray-900 dark:text-white">
                                        {row.protected_area || "—"}
                                    </p>
                                    <p className="mt-0.5 break-words text-[11px] text-gray-500">
                                        {responsibleOfficeFor(row) ||
                                            row.target_office ||
                                            "—"}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: "activity",
                            label: "Report / Activity",
                            headerClassName: "min-w-[180px]",
                            cellClassName: "min-w-[180px]",
                            render: (row) => (
                                <div className="min-w-0">
                                    <p className="break-words font-semibold text-gray-800 dark:text-gray-100">
                                        {row.activity_name ||
                                            row.document_type ||
                                            row.report_type ||
                                            "—"}
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-gray-500">
                                        {row.reporting_period || "—"}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: "status",
                            label: "Current Status",
                            headerClassName: "min-w-[130px]",
                            cellClassName: "min-w-[130px]",
                            render: (row) => (
                                <Badge value={compactStatusFor(row)} />
                            ),
                        },
                        {
                            key: "required_action",
                            label: "Required Action",
                            headerClassName: "min-w-[135px]",
                            cellClassName: "min-w-[135px]",
                            render: (row) => (
                                <span className="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                    {requiredActionFor(row)}
                                </span>
                            ),
                        },
                        {
                            key: "deadline_submission",
                            label: "Deadline",
                            headerClassName: "min-w-[115px]",
                            cellClassName: "min-w-[115px]",
                            render: (row) => (
                                <span className="whitespace-nowrap text-xs font-semibold text-gray-800 dark:text-gray-200">
                                    {plainDate(row.deadline_submission) || "—"}
                                </span>
                            ),
                        },
                        {
                            key: "open",
                            label: "Action",
                            headerClassName: "min-w-[70px]",
                            cellClassName: "min-w-[70px]",
                            render: () => (
                                <span className="text-xs font-bold text-green-700 dark:text-green-300">
                                    Open
                                </span>
                            ),
                        },
                    ],
        [tab],
    );
    useEffect(
        () => setTab(trackingContext.view || "incoming"),
        [trackingContext.view],
    );
    useEffect(() => {
        if (tab !== "incoming" || isGlobalMonitoring) return;
        if (!incomingActionTabs.includes(incomingActionTab)) {
            setIncomingActionTab(incomingActionTabs[0] || null);
        }
    }, [tab, incomingActionTab, incomingActionTabs, isGlobalMonitoring]);
    useEffect(() => setSearch(filters.search || ""), [filters.search]);
    useEffect(() => setModule(filters.module || ""), [filters.module]);
    useEffect(
        () => setProtectedAreaId(filters.protected_area_id || ""),
        [filters.protected_area_id],
    );
    useEffect(() => setStatus(filters.status || ""), [filters.status]);
    const navigateFilters = (changes) =>
        router.get(
            route("submission-tracking.index"),
            {
                ...filters,
                view: tab,
                search: search || undefined,
                module: module || undefined,
                protected_area_id: protectedAreaId || undefined,
                status: status || undefined,
                ...changes,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    useEffect(() => {
        if (search === (filters.search || "")) return undefined;
        const timer = window.setTimeout(
            () => navigateFilters({ search: search || undefined }),
            300,
        );
        return () => window.clearTimeout(timer);
    }, [search]);
    const visibleDetails = useMemo(() => {
        if (
            details &&
            ((trackingContext.selected_record?.source === details.source &&
                trackingContext.selected_record?.source_id ===
                    details.source_id) ||
                rows.some(
                    (row) =>
                        row.source === details.source &&
                        row.source_id === details.source_id,
                ))
        )
            return details;
        return rows[0] || null;
    }, [rows, details, trackingContext.selected_record]);
    useEffect(() => {
        const linked = trackingContext.selected_record;
        const visibleKey =
            details &&
            rows.some(
                (row) =>
                    row.source === details.source &&
                    row.source_id === details.source_id,
            )
                ? details.source + "-" + details.source_id
                : "";
        const next =
            linked ||
            rows.find(
                (row) => row.source + "-" + row.source_id === visibleKey,
            ) ||
            rows[0] ||
            null;
        setDetails((previous) =>
            previous?.source === next?.source &&
            previous?.source_id === next?.source_id &&
            previous === next
                ? previous
                : next,
        );
        if (
            selected &&
            !rows.some(
                (row) =>
                    row.source === selected.source &&
                    row.source_id === selected.source_id,
            )
        )
            setSelected(null);
        if (!next) setShowFullDetails(false);
    }, [
        rows,
        incomingRows,
        tab,
        search,
        module,
        protectedAreaId,
        status,
        pagination.current_page,
        trackingContext.selected_record,
    ]);
    const continueWithFreshIncomingRow = (target, page) => {
        if (!target) return;
        const freshIncomingRows = page?.props?.workspaceQueues?.incoming || [];
        const continuedRow = freshIncomingRows.find(
            (row) => row.source === target.source && row.source_id === target.source_id,
        );
        if (!continuedRow) {
            setDetails(null);
            setShowFullDetails(false);
            return;
        }
        setTab("incoming");
        setIncomingActionTab(continuedRow.incoming_action_category || null);
        setDetails(continuedRow);
        setShowFullDetails(true);
    };
    const closeSelectedAction = () => { form.reset(); form.clearErrors(); setSelected(null); };
    const closeInternalRouting = () => { internalForm.reset(); internalForm.clearErrors(); setRoutingStage(null); };
    const submit = (event) => {
        event.preventDefault();
        if (
            genericAction?.remarks_required &&
            !String(form.data.remarks || "").trim()
        ) {
            form.setError("remarks", "Correction remarks are required.");
            return;
        }
        const continuationTarget = selected
            ? { source: selected.source, source_id: selected.source_id }
            : null;
        form.transform((data) => {
            const next = { ...data };
            if (!(typeof File !== "undefined" && next.attachment instanceof File)) delete next.attachment;
            return next;
        });
        form.post(
            route("submission-tracking.transition", [
                selected.source,
                selected.source_id,
                form.data.stage,
            ]),
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: (page) => {
                    continueWithFreshIncomingRow(continuationTarget, page);
                    setSelected(null);
                    form.reset();
                },
            },
        );
    };
    const submitReview = (event) => {
        event.preventDefault();
        reviewForm.post(
            route("submission-tracking.mov.review", [
                reviewing.source,
                reviewing.source_id,
            ]),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReviewing(null);
                    reviewForm.reset();
                },
            },
        );
    };
    const submitInternal = (event) => {
        event.preventDefault();
        const continuationTarget = details
            ? { source: details.source, source_id: details.source_id }
            : null;
        internalForm.post(
            route("submission-tracking.internal-routing", [
                details.source,
                details.source_id,
                routingStage.key,
            ]),
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: (page) => {
                    continueWithFreshIncomingRow(continuationTarget, page);
                    setRoutingStage(null);
                    internalForm.reset();
                },
            },
        );
    };
    const submitCorrection = (event) => {
        event.preventDefault();
        correctionForm.patch(
            route("submission-tracking.correct-routing", [
                correction.source,
                correction.source_id,
            ]),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCorrection(null);
                    correctionForm.reset();
                },
            },
        );
    };
    const openCorrection = (row) => {
        const dates = {};
        [
            "date_report_released_cenro",
            "date_received_penro",
            "date_endorsed_regional",
        ].forEach((field) => {
            if (Object.prototype.hasOwnProperty.call(row, field))
                dates[field] = row[field] || "";
        });
        const releaseEvents = Object.fromEntries(
            (row.release_events || []).map((event) => [
                event.id,
                event.date_report_released_cenro || "",
            ]),
        );
        const internalEvents = Object.fromEntries(
            (row.routing_timeline || [])
                .filter((event) => event.is_internal && event.occurred_at)
                .map((event) => [
                    event.key,
                    localDateTimeInputValue(event.occurred_at),
                ]),
        );
        correctionForm.setData({
            dates,
            release_events: releaseEvents,
            internal_events: internalEvents,
            reason: "",
            password: "",
        });
        correctionForm.clearErrors();
        setDetails(null);
        setCorrection(row);
    };

    const viewLabels = {
        incoming: "Incoming Submissions",
        outgoing: "Outgoing Submissions",
        history: "Submission History",
    };
    const tabLabel = viewLabels[tab] || viewLabels.incoming;
    const queueHelper =
        (isGlobalMonitoring
            ? monitoringViewDescriptions
            : operationalViewDescriptions)[tab] || "Submission workspace.";
    const currentPage = pagination.current_page || 1;
    const pageSize = pagination.per_page || 25;
    const firstVisible = rows.length ? (currentPage - 1) * pageSize + 1 : 0;
    const lastVisible = rows.length ? firstVisible + rows.length - 1 : 0;
    const filtersActive = Boolean(
        search || module || protectedAreaId || status,
    );
    const resetFilters = () => {
        setSearch("");
        setModule("");
        setProtectedAreaId("");
        setStatus("");
        navigateFilters({
            search: undefined,
            module: undefined,
            protected_area_id: undefined,
            status: undefined,
            page: undefined,
        });
    };
    const paginationControls = (
        <div className="flex flex-col gap-2 text-xs text-gray-500 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
            <span>
                {rows.length
                    ? "Showing " +
                      firstVisible +
                      "–" +
                      lastVisible +
                      " on this page"
                    : "Showing 0 reports"}
            </span>
            <div className="flex items-center gap-2">
                <span className="font-semibold text-gray-700 dark:text-gray-200">
                    Page {currentPage}
                </span>
                <button
                    type="button"
                    aria-label="Previous page"
                    disabled={currentPage <= 1}
                    className="rounded-lg border border-gray-200 p-1.5 text-gray-600 transition hover:border-green-300 hover:text-green-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300"
                    onClick={() => navigateFilters({ page: currentPage - 1 })}
                >
                    <svg
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.7"
                        className="h-4 w-4"
                        aria-hidden="true"
                    >
                        <path
                            d="m12.5 4.5-5 5.5 5 5.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    </svg>
                </button>
                <button
                    type="button"
                    aria-label="Next page"
                    disabled={!pagination.has_more}
                    className="rounded-lg border border-gray-200 p-1.5 text-gray-600 transition hover:border-green-300 hover:text-green-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300"
                    onClick={() => navigateFilters({ page: currentPage + 1 })}
                >
                    <svg
                        viewBox="0 0 20 20"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.7"
                        className="h-4 w-4"
                        aria-hidden="true"
                    >
                        <path
                            d="m7.5 4.5 5 5.5-5 5.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    </svg>
                </button>
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout title="Submission Tracking">
            <div className="submission-tracking-page">
                <PageHeader title={tabLabel} description={queueHelper} />
                <div className="mt-4 space-y-4 sm:mt-5">
                    {tab === "incoming" && incomingActionTabs.length > 0 && (
                        <div className="flex flex-wrap gap-2" aria-label="Incoming action filters">
                            {incomingActionTabs.map((category) => (
                                <button
                                    key={category}
                                    type="button"
                                    onClick={() => setIncomingActionTab(category)}
                                    className={`rounded-lg px-3 py-2 text-xs font-bold transition ${incomingActionTab === category ? "bg-green-700 text-white" : "border border-gray-200 bg-white text-gray-700 hover:border-green-300 hover:bg-green-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"}`}
                                >
                                    {incomingActionLabels[category]}
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="submission-tracking-filterbar flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center">
                        <div className="min-w-0 flex-1">
                            <FloatingInput
                                id="submission-tracking-search"
                                label="Search protected area, report, or office"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                size="sm"
                            />
                        </div>
                        <div className="w-full lg:w-40">
                            <FloatingSelect
                                id="submission-tracking-module"
                                label="Module"
                                value={module}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    setModule(value);
                                    navigateFilters({
                                        module: value || undefined,
                                    });
                                }}
                                size="sm"
                            >
                                <option value="">All modules</option>
                                {(filterOptions.modules || []).map((value) => (
                                    <option key={value}>{value}</option>
                                ))}
                            </FloatingSelect>
                        </div>
                        <div className="w-full lg:w-52">
                            <FloatingSelect
                                id="submission-tracking-area"
                                label="Protected Area"
                                value={protectedAreaId}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    setProtectedAreaId(value);
                                    navigateFilters({
                                        protected_area_id: value || undefined,
                                    });
                                }}
                                size="sm"
                            >
                                <option value="">All protected areas</option>
                                {(filterOptions.protectedAreas || []).map(
                                    (area) => (
                                        <option key={area.id} value={area.id}>
                                            {area.name}
                                        </option>
                                    ),
                                )}
                            </FloatingSelect>
                        </div>
                        <div className="w-full lg:w-40">
                            <FloatingSelect
                                id="submission-tracking-status"
                                label="Status"
                                value={status}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    setStatus(value);
                                    navigateFilters({
                                        status: value || undefined,
                                    });
                                }}
                                size="sm"
                            >
                                <option value="">All statuses</option>
                                {(filterOptions.statuses || []).map((value) => (
                                    <option key={value}>{value}</option>
                                ))}
                            </FloatingSelect>
                        </div>
                        <button
                            type="button"
                            onClick={resetFilters}
                            disabled={!filtersActive}
                            className="inline-flex h-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 px-3 text-xs font-bold text-gray-600 transition hover:border-green-300 hover:bg-green-50 hover:text-green-800 disabled:cursor-not-allowed disabled:opacity-45 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            Clear
                        </button>
                    </div>
                    <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_400px]">
                        <div className="min-w-0">
                            <CrudTable
                                title={tabLabel}
                                subtitle={queueHelper}
                                helperText="Select a row to review its details."
                                headerAside={
                                    <span className="text-xs font-semibold text-gray-500 dark:text-gray-400">
                                        {rows.length}{" "}
                                        {rows.length === 1
                                            ? "report"
                                            : "reports"}
                                    </span>
                                }
                                columns={columns}
                                rows={rows}
                                rowKey={(row) =>
                                    row.source + "-" + row.source_id
                                }
                                selectedKeys={
                                    details
                                        ? [
                                              details.source +
                                                  "-" +
                                                  details.source_id,
                                          ]
                                        : []
                                }
                                onRowClick={setDetails}
                                emptyTitle={
                                    tab === "history"
                                        ? "No completed submissions found."
                                        : tab === "incoming"
                                          ? incomingActionTab
                                            ? `No documents currently require ${incomingActionLabels[incomingActionTab].toLowerCase()} action from your office.`
                                            : "No incoming submissions require your action."
                                          : tab === "outgoing"
                                            ? "No outgoing submissions found."
                                            : "No submissions found."
                                }
                                emptyDescription={
                                    filtersActive
                                        ? "Try changing a filter or clear the selected filters."
                                        : ""
                                }
                                preserveFrameWhenEmpty
                                tableClassName="min-w-[760px]"
                                tableContainerClassName="max-h-[680px] overflow-y-auto"
                                tableHeaderClassName="sticky top-0 z-20"
                                pagination={paginationControls}
                                className="submission-tracking-queue"
                                compact
                                compactEmpty
                            />
                        </div>
                        <div className="xl:sticky xl:top-4">
                            <SubmissionDetailsPanel
                                row={visibleDetails}
                                onAction={(nextAction) => {
                                    form.setData({
                                        date: "",
                                        stage: nextAction.key,
                                        remarks: "",
                                        correction_reason_key: "",
                                        correction_detail: "",
                                        attachment: null,
                                    });
                                    form.clearErrors();
                                    setSelected(visibleDetails);
                                }}
                                onViewFullDetails={() => {
                                    setDetails(visibleDetails);
                                    setShowFullDetails(true);
                                }}
                            />
                        </div>
                    </div>
                </div>
            </div>
            <CrudDetailsModal
                compact
                open={Boolean(showFullDetails && details)}
                title="Submission Full Details"
                subtitle={
                    details
                        ? `${details.module || "Report"} · ${details.protected_area || "Protected area unavailable"}`
                        : ""
                }
                onClose={() => setShowFullDetails(false)}
                canEdit={canCorrectSubmissionRouting}
                onEdit={() => openCorrection(details)}
                editLabel="Correct Routing Record"
                summary={
                    details && (
                        <CrudSummaryGrid
                            items={[
                                { label: "Module", value: details.module },
                                {
                                    label: "Protected Area",
                                    value: details.protected_area,
                                },
                                {
                                    label: "Reporting Period",
                                    value: details.reporting_period,
                                },
                                {
                                    label: details.mov_processing?.applicable
                                        ? "Workflow Status"
                                        : "Routing Status",
                                    render: () => (
                                        <Badge
                                            value={
                                                details.mov_processing
                                                    ?.applicable
                                                    ? details.mov_processing
                                                          .workflow_status
                                                    : details.submission_status
                                            }
                                        />
                                    ),
                                },
                                {
                                    label: "Timeliness",
                                    render: () => (
                                        <Badge value={details.timeliness} />
                                    ),
                                },
                                ...(details.pamb_routing_applicable
                                    ? [
                                          {
                                              label: "Current Document Location",
                                              value: details.current_document_location,
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    )
                }
            >
                {details?.mov_processing?.applicable && (
                    <PambMovProgress
                        row={details}
                        context={trackingContext}
                        onSubmit={(row, options = {}) =>
                            router.post(
                                route("submission-tracking.mov.submit-review", [
                                    row.source,
                                    row.source_id,
                                ]),
                                {},
                                { preserveScroll: true, ...options },
                            )
                        }
                        onReview={(row, decision) => {
                            reviewForm.setData({ decision, remarks: "" });
                            reviewForm.clearErrors();
                            setReviewing(row);
                        }}
                        onRelease={(row) => {
                            form.setData({
                                date: localDateInputValue(),
                                stage: "cenro_release",
                            });
                            form.clearErrors();
                            setSelected(row);
                        }}
                    />
                )}
                {canAdminRoutingOverride && details && (
                    <div className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/30">
                        <p className="text-xs font-extrabold uppercase tracking-wide text-amber-900 dark:text-amber-200">
                            Administrative emergency control
                        </p>
                        <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">
                            This separate override requires a fresh passkey and
                            records your account, reason, and accountable
                            category. It does not impersonate another user.
                        </p>
                        <button
                            type="button"
                            onClick={() => openAdminOverride(details)}
                            className="mt-3 rounded-lg bg-amber-700 px-3 py-2 text-xs font-bold text-white hover:bg-amber-800"
                        >
                            Admin Override
                        </button>
                        {overrideError && !override && (
                            <p className="mt-2 text-xs font-semibold text-red-700">
                                {overrideError}
                            </p>
                        )}
                    </div>
                )}
                {details?.pamb_routing_applicable ? (
                    <PambRoutingTimeline
                        row={details}
                        actions={details.routing?.actions || []}
                        onRecord={(stage) => {
                            internalForm.setData({
                                remarks: "",
                                stage: stage.stage_key || stage.key,
                                attachment: null,
                            });
                            internalForm.clearErrors();
                            setRoutingStage(stage);
                        }}
                        onCanonicalAction={(stage) => {
                            const actionKey = stage.key || stage.stage_key;
                            const correction = String(actionKey).startsWith("return_for_correction_");
                            const correctionCycleAction = ["receive_correction", "forward_to_penro_records"].includes(actionKey);
                            form.setData({
                                date: correction || correctionCycleAction ? "" : localDateInputValue(),
                                stage: correction
                                    ? actionKey
                                    : correctionCycleAction
                                      ? actionKey
                                    : [
                                            "penro_records_received",
                                            "penro_receipt",
                                            "receive_at_penro_records",
                                        ].includes(actionKey)
                                      ? "penro_receipt"
                                      : "regional_endorsement",
                                remarks: "",
                                correction_reason_key: "",
                                correction_detail: "",
                                attachment: null,
                            });
                            form.clearErrors();
                            setSelected(details);
                        }}
                    />
                ) : (
                    <DocumentRoutingTimeline
                        row={details}
                        onAction={(nextAction) => {
                            form.setData({
                                date: "",
                                stage: nextAction.key,
                                remarks: "",
                                correction_reason_key: "",
                                correction_detail: "",
                                attachment: null,
                            });
                            form.clearErrors();
                            setSelected(details);
                        }}
                    />
                )}
            </CrudDetailsModal>
            <CrudFormModal
                open={Boolean(selected)}
                mode="edit"
                title={selectedActionLabel}
                subtitle={
                    genericAction
                        ? "The event timestamp is recorded by the server."
                        : "Record the real-world routing event only. eDATS does not electronically transmit the official document."
                }
                onClose={closeSelectedAction}
                onSubmit={submit}
                processing={form.processing}
                errors={form.errors}
                saveLabel={selectedActionLabel}
                maxWidth="max-w-xl"
            >
                {" "}
                <CrudSection title="Document copy">
                        <RoutingAttachmentField
                            currentDocument={selected?.current_document}
                            file={form.data.attachment}
                            onChange={(value) => form.setData("attachment", value instanceof File ? value : null)}
                        error={form.errors.attachment}
                        disabled={form.processing}
                        processing={form.processing}
                        uploadProgress={form.progress}
                            attachmentAllowed={genericAction?.correction_reference_allowed || String(form.data.stage || '').startsWith('return_for_correction_') ? true : genericAction?.attachment_allowed !== false && selected?.routing?.attachment_allowed !== false}
                            correctionAttachment={Boolean(genericAction?.correction_reference_allowed || String(form.data.stage || '').startsWith('return_for_correction_'))}
                    />
                </CrudSection>
                <CrudSection
                    title={
                        genericAction
                            ? "Document Routing Event"
                            : "Monitoring Event"
                    }
                >
                    {selected?.current_document && (
                        <button
                            type="button"
                            onClick={() => setPreviewRow(selected)}
                            className="mb-3 rounded-lg border border-green-700 px-3 py-2 text-xs font-bold text-green-800 hover:bg-green-50 dark:text-green-200"
                        >
                            Preview MOV / Report
                        </button>
                    )}
                    {genericAction ? (
                        <>
                        {genericAction.receipt_correction_context && <>
                            <FloatingSelect id="submission-tracking-correction-reason" label="Correction reason" required value={form.data.correction_reason_key} onChange={(event) => form.setData("correction_reason_key", event.target.value)} error={form.errors.correction_reason_key}>
                                <option value="">Select a reason</option>
                                {(genericAction.receipt_correction_context === "cenro_records"
                                    ? [["missing_signature", "Missing Signature"], ["missing_attachment", "Incomplete Attachment"], ["incomplete_document", "Incomplete / Incorrect Document"], ["other", "Other"]]
                                    : [["missing_endorsement", "Missing Endorsement"], ["missing_attachment", "Missing Attachment"], ["missing_received_copy", "Missing Received Copy"], ["incomplete_document", "Incomplete / Incorrect Document"], ["other", "Other"]]
                                ).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </FloatingSelect>
                            <FloatingTextarea id="submission-tracking-correction-detail" label={form.data.correction_reason_key === "other" ? "Explain the correction reason" : "Remarks / details (optional)"} required={form.data.correction_reason_key === "other"} rows={3} value={form.data.correction_detail} onChange={(event) => form.setData("correction_detail", event.target.value)} error={form.errors.correction_detail} />
                        </>}
                        {!genericAction.receipt_correction_context && <FloatingTextarea
                            id="submission-tracking-remarks"
                            label={
                                genericAction?.remarks_required
                                    ? "Correction remarks"
                                    : "Remarks / Reference (optional)"
                            }
                            required={Boolean(genericAction?.remarks_required)}
                            rows={3}
                            value={form.data.remarks}
                            onChange={(event) =>
                                form.setData("remarks", event.target.value)
                            }
                            error={form.errors.remarks}
                        />}
                        </>
                    ) : (
                        <>
                            <p className="mb-3 text-xs text-gray-600 dark:text-gray-300">
                                This date records when the office released,
                                received, or endorsed the report / MOV.
                                Backdating is allowed when chronology is valid.
                            </p>
                            <DatePicker
                                id="submission-tracking-date"
                                label={action[2]}
                                value={form.data.date}
                                onChange={(value) =>
                                    form.setData("date", value)
                                }
                                error={form.errors.date}
                            />
                        </>
                    )}
                </CrudSection>
            </CrudFormModal>
            <CrudFormModal
                open={Boolean(override)}
                mode="edit"
                title="Administrative Override"
                subtitle="You are performing a currently valid workflow action as an emergency administrative override. Your identity, reason, and passkey verification will be recorded."
                onClose={() => !overrideProcessing && setOverride(null)}
                onSubmit={submitAdminOverride}
                processing={overrideProcessing}
                errors={{}}
                saveLabel="Verify Passkey and Execute"
                maxWidth="max-w-xl"
            >
                {override && (
                    <CrudSection title="Override Details">
                        <div className="grid gap-2 text-xs text-gray-700 dark:text-gray-200">
                            <p>
                                Current stage:{" "}
                                <strong>{override.current_stage}</strong>
                            </p>
                            <p>
                                Current location:{" "}
                                <strong>
                                    {override.current_location || FALLBACK}
                                </strong>
                            </p>
                            <p>
                                Override for:{" "}
                                <strong>
                                    {override.accountable_category} —{" "}
                                    {override.accountable_office || FALLBACK}
                                </strong>
                            </p>
                        </div>
                        <div className="mt-4 space-y-3">
                            <FloatingSelect
                                id="admin-override-action"
                                label="Currently valid action"
                                value={overrideAction}
                                onChange={(event) =>
                                    setOverrideAction(event.target.value)
                                }
                                required
                            >
                                {(override.actions || []).map((item) => (
                                    <option key={item.key} value={item.key}>
                                        {item.action_label}
                                    </option>
                                ))}
                            </FloatingSelect>
                            <FloatingTextarea
                                id="admin-override-reason"
                                label="Mandatory override reason"
                                required
                                rows={4}
                                value={overrideReason}
                                onChange={(event) =>
                                    setOverrideReason(event.target.value)
                                }
                                error={overrideError}
                            />
                            <p className="text-xs text-gray-600 dark:text-gray-300">
                                A fresh WebAuthn/passkey assertion is required
                                for this one action. Forwarding, receipt,
                                approval, and release remain separate.
                            </p>
                        </div>
                    </CrudSection>
                )}
            </CrudFormModal>
            <CrudFormModal
                open={Boolean(routingStage)}
                mode="edit"
                title={standardActionLabel(
                    routingStage?.action_label ||
                        routingStage?.label ||
                        "Routing Event",
                )}
                subtitle="Record the real-world event only; eDATS does not transmit the document."
                onClose={() =>
                    !internalForm.processing && closeInternalRouting()
                }
                onSubmit={submitInternal}
                processing={internalForm.processing}
                errors={internalForm.errors}
                saveLabel={standardActionLabel(
                    routingStage?.action_label ||
                        routingStage?.label ||
                        "Routing Event",
                )}
                maxWidth="max-w-xl"
            >
                <CrudSection title="Document / MOV">
                    <RoutingAttachmentField
                        currentDocument={details?.current_document}
                        file={internalForm.data.attachment}
                        onChange={internalForm.setData.bind(null, "attachment")}
                        error={internalForm.errors.attachment}
                        disabled={internalForm.processing}
                        processing={internalForm.processing}
                        uploadProgress={internalForm.progress}
                        attachmentAllowed={routingStage?.attachment_allowed !== false && details?.routing?.attachment_allowed !== false}
                    />
                    <div className="mt-2 grid gap-1 text-xs text-gray-500 dark:text-gray-400 sm:grid-cols-2">
                        <p>
                            Current location:{" "}
                            <span className="font-semibold text-gray-800 dark:text-gray-200">
                                {details?.current_document_location}
                            </span>
                        </p>
                        <p>
                            Next destination:{" "}
                            <span className="font-semibold text-gray-800 dark:text-gray-200">
                                {routingStage?.destination}
                            </span>
                        </p>
                    </div>
                    {routingStage?.previous_occurred_at && (
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Prior event:{" "}
                            {formatReportDateTime(
                                routingStage.previous_occurred_at,
                                FALLBACK,
                            )}
                        </p>
                    )}
                </CrudSection>
                <CrudSection title="Internal Routing Event">
                    <p className="mb-3 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200">
                        Event time: recorded automatically when saved.
                    </p>
                    <FloatingTextarea
                        id="pamb-routing-remarks"
                        label="Remarks / Reference (optional)"
                        rows={3}
                        value={internalForm.data.remarks}
                        onChange={(event) =>
                            internalForm.setData("remarks", event.target.value)
                        }
                        error={internalForm.errors.remarks}
                    />
                </CrudSection>
            </CrudFormModal>
            <CrudFormModal
                open={Boolean(reviewing)}
                mode="edit"
                title={
                    reviewForm.data.decision === "needs_correction"
                        ? "Return MOV/report for Correction"
                        : "Mark MOV/report Ready for Release"
                }
                subtitle="Record the Chief review decision. This is operational monitoring, not an electronic approval chain."
                onClose={() => !reviewForm.processing && setReviewing(null)}
                onSubmit={submitReview}
                processing={reviewForm.processing}
                errors={reviewForm.errors}
                saveLabel={
                    reviewForm.data.decision === "needs_correction"
                        ? "Return for Correction"
                        : "Ready for Release"
                }
                maxWidth="max-w-xl"
            >
                <CrudSection title="Chief Review">
                    <FloatingTextarea
                        id="pamb-review-remarks"
                        label={
                            reviewForm.data.decision === "needs_correction"
                                ? "Correction remarks"
                                : "Review remarks (optional)"
                        }
                        required={
                            reviewForm.data.decision === "needs_correction"
                        }
                        rows={4}
                        value={reviewForm.data.remarks}
                        onChange={(event) =>
                            reviewForm.setData("remarks", event.target.value)
                        }
                        error={reviewForm.errors.remarks}
                    />
                </CrudSection>
            </CrudFormModal>
            <CrudFormModal
                open={Boolean(correction)}
                mode="edit"
                title="Correct Routing Record"
                subtitle="Administrative correction. Every changed date is retained in the audit trail."
                onClose={() =>
                    !correctionForm.processing && setCorrection(null)
                }
                onSubmit={submitCorrection}
                processing={correctionForm.processing}
                errors={correctionForm.errors}
                saveLabel="Confirm Correction"
                maxWidth="max-w-xl"
            >
                <CrudSection title="Record / Module">
                    <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">
                        {correction?.module || FALLBACK}
                    </p>
                    <p className="mt-1 text-xs text-gray-500">
                        Record ID:{" "}
                        {correction
                            ? `${correction.source}-${correction.source_id}`
                            : FALLBACK}
                    </p>
                </CrudSection>
                <CrudSection title="Current Routing Dates">
                    <div className="space-y-3">
                        {Object.prototype.hasOwnProperty.call(
                            correctionForm.data.dates,
                            "date_report_released_cenro",
                        ) && (
                            <DatePicker
                                id="correction-cenro-release"
                                label="CENRO Released"
                                value={
                                    correctionForm.data.dates
                                        .date_report_released_cenro || ""
                                }
                                onChange={(value) =>
                                    correctionForm.setData("dates", {
                                        ...correctionForm.data.dates,
                                        date_report_released_cenro: value,
                                    })
                                }
                                error={
                                    correctionForm.errors[
                                        "dates.date_report_released_cenro"
                                    ]
                                }
                            />
                        )}
                        {Object.prototype.hasOwnProperty.call(
                            correctionForm.data.dates,
                            "date_received_penro",
                        ) && (
                            <DatePicker
                                id="correction-penro-receipt"
                                label="PENRO Received"
                                value={
                                    correctionForm.data.dates
                                        .date_received_penro || ""
                                }
                                onChange={(value) =>
                                    correctionForm.setData("dates", {
                                        ...correctionForm.data.dates,
                                        date_received_penro: value,
                                    })
                                }
                                error={
                                    correctionForm.errors[
                                        "dates.date_received_penro"
                                    ]
                                }
                            />
                        )}
                        {Object.prototype.hasOwnProperty.call(
                            correctionForm.data.dates,
                            "date_endorsed_regional",
                        ) && (
                            <DatePicker
                                id="correction-regional-endorsement"
                                label="Regional Endorsed"
                                value={
                                    correctionForm.data.dates
                                        .date_endorsed_regional || ""
                                }
                                onChange={(value) =>
                                    correctionForm.setData("dates", {
                                        ...correctionForm.data.dates,
                                        date_endorsed_regional: value,
                                    })
                                }
                                error={
                                    correctionForm.errors[
                                        "dates.date_endorsed_regional"
                                    ]
                                }
                            />
                        )}
                        {Object.entries(
                            correctionForm.data.release_events || {},
                        ).map(([id, value]) => (
                            <DatePicker
                                key={id}
                                id={`correction-release-event-${id}`}
                                label={`CENRO Release Event ${id}`}
                                value={value || ""}
                                onChange={(nextValue) =>
                                    correctionForm.setData("release_events", {
                                        ...correctionForm.data.release_events,
                                        [id]: nextValue,
                                    })
                                }
                            />
                        ))}
                    </div>
                </CrudSection>
                {Object.keys(correctionForm.data.internal_events || {}).length >
                    0 && (
                    <CrudSection title="Current Internal Routing Events">
                        <p className="mb-3 text-xs text-gray-600 dark:text-gray-300">
                            Adjust the time of an existing internal routing
                            event. The event date remains part of the saved
                            value and the original record stays in the
                            correction audit trail.
                        </p>
                        <div className="space-y-3">
                            {Object.entries(
                                correctionForm.data.internal_events,
                            ).map(([stage, value]) => (
                                <PremiumTimePicker
                                    key={stage}
                                    id={`correction-internal-${stage}`}
                                    label={stage.replaceAll("_", " ")}
                                    value={value || ""}
                                    onChange={(nextValue) =>
                                        correctionForm.setData(
                                            "internal_events",
                                            {
                                                ...correctionForm.data
                                                    .internal_events,
                                                [stage]: nextValue,
                                            },
                                        )
                                    }
                                    error={
                                        correctionForm.errors[
                                            `internal_events.${stage}`
                                        ]
                                    }
                                />
                            ))}
                        </div>
                    </CrudSection>
                )}
                <CrudSection title="Authorization">
                    <div className="space-y-4">
                        <FloatingInput
                            id="correction-reason"
                            label="Correction reason"
                            required
                            value={correctionForm.data.reason}
                            onChange={(event) =>
                                correctionForm.setData(
                                    "reason",
                                    event.target.value,
                                )
                            }
                            error={correctionForm.errors.reason}
                        />
                        <FloatingInput
                            id="correction-password"
                            label="Current password"
                            required
                            type="password"
                            value={correctionForm.data.password}
                            onChange={(event) =>
                                correctionForm.setData(
                                    "password",
                                    event.target.value,
                                )
                            }
                            error={correctionForm.errors.password}
                        />
                    </div>
                </CrudSection>
            </CrudFormModal>
            <DocumentPreviewDialog
                open={Boolean(previewRow)}
                row={previewRow}
                onClose={() => setPreviewRow(null)}
            />
        </AuthenticatedLayout>
    );
}
