export function progressMeasureForSubmission(row) {
    const routing = row?.routing || row?.routing_summary || {};
    // MOV milestones have their own percentage and can reach 100% before
    // the report completes routing. The shared bar represents routing only;
    // MOV progress remains available in its separate milestone presentation.
    const value = Number(routing.processing_percentage);

    if (!Number.isFinite(value) || value <= 0) return null;

    return {
        label: "Routing Progress",
        value: Math.max(0, Math.min(100, value)),
    };
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
