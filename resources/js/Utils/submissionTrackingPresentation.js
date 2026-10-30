export function progressMeasureForSubmission(row) {
    const routing = row?.routing || row?.routing_summary || {};
    // This is the existing stage-based processing measure. MOV milestones
    // remain separate, and 100% does not replace the terminal custody flag.
    const value = Number(routing.processing_percentage);

    if (!Number.isFinite(value) || value <= 0) return null;

    return {
        label: "Processing Progress",
        value: Math.max(0, Math.min(100, value)),
    };
}

export function submissionReferenceLabelForTrackingRow(row) {
    if (row?.tracking_number) return row.tracking_number;
    if (!row?.source) return null;
    if (row.source === "engp") {
        return row.source_id == null
            ? "Development report"
            : `Development report #${row.source_id}`;
    }

    return `${row.source} #${row.source_id}`;
}

export function protectedAreaLabelForTrackingRow(row) {
    if (row?.source === "engp" && !row?.protected_area) return null;
    return row?.protected_area || "Not recorded";
}

export function cenroReleaseFallbackForRow(row) {
    if (row?.cenro_release_applicable === false) return "Not applicable";
    if (row?.cenro_release_state === "awaiting_cenro_release") return "Awaiting CENRO release";
    return "Not recorded";
}

export function finalArchiveStatusNoticeForTrackingRow(row) {
    const routing = row?.routing || row?.routing_summary || {};
    const currentOffice = String(routing.current_location || "");

    if (
        row?.source !== "engp" ||
        row?.routing_complete !== true ||
        row?.has_verified_final_archive !== false ||
        !/regional office/i.test(currentOffice)
    ) {
        return null;
    }

    return "Released to Regional · Final archive not verified";
}

export function visibleTimelineForCapturedRoute(row, timeline = []) {
    const steps = Array.isArray(timeline) ? timeline : [];
    const routing = row?.routing || row?.routing_summary || {};
    const position = routing.route_position;

    if (!position) return steps;

    return steps.filter((step) => {
        const isSyntheticSkippedPosition =
            step?.status === "skipped" &&
            step?.display_status === "skipped" &&
            step?.event_type === null &&
            step?.display_status_label ===
                "Skipped by Routing Workflow Settings";

        if (!isSyntheticSkippedPosition) return true;

        if (
            ["office_initial_skipped", "office_final_skipped"].includes(
                step?.key,
            ) &&
            position.office_penro_enabled === false
        ) {
            return false;
        }

        if (
            step?.key === "tsd_initial_skipped" &&
            position.penro_tsd_chief_enabled === false
        ) {
            return false;
        }

        return true;
    });
}

export function timelinePresentation(timeline = [], expanded = false) {
    const pendingSteps = timeline.filter((step) => step?.status === "pending");
    const hiddenSteps = expanded ? [] : pendingSteps.slice(1);
    const nextStep = pendingSteps[0];

    return {
        pendingSteps,
        hiddenSteps,
        visibleSteps: expanded
            ? timeline
            : timeline.filter(
                  (step) => step?.status !== "pending" || step === nextStep,
              ),
        hasToggle: pendingSteps.length > 1,
    };
}
