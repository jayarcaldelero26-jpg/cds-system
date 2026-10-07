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
