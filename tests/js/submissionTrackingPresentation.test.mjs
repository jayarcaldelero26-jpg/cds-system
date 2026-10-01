import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import {
    progressMeasureForSubmission,
    timelinePresentation,
} from "../../resources/js/Utils/submissionTrackingPresentation.js";

test("shared progress keeps valid routing progress and does not substitute a completed PAMB MOV measure", async () => {
    const measure = progressMeasureForSubmission({
        mov_processing: { applicable: true, status_key: "released_by_cenro", percent: 100 },
        routing: { processing_percentage: 35 },
    });
    assert.deepEqual(measure, { label: "Routing Progress", value: 35 });

    assert.deepEqual(
        progressMeasureForSubmission({
            mov_processing: { applicable: true, status_key: "released_by_cenro", percent: 100 },
            routing: { processing_percentage: 80 },
        }),
        { label: "Routing Progress", value: 80 },
    );

    assert.deepEqual(
        progressMeasureForSubmission({ routing: { processing_percentage: 100 }, mov_processing: { applicable: true, percent: 35 } }),
        { label: "Routing Progress", value: 100 },
    );

    const component = await readFile(
        new URL("../../resources/js/Components/SubmissionTracking/SubmissionTrackingProgress.jsx", import.meta.url),
        "utf8",
    );
    assert.match(component, /if \(!measure\) return null/);
    assert.match(component, /bg-gradient-to-r from-green-800 via-emerald-700 to-blue-800/);
    assert.match(component, /submission-tracking-progress__sweep/);
    assert.match(component, /h-3 w-full/);
    assert.match(component, /aria-valuenow=\{progress\}/);
    assert.match(component, /style=\{\{ width: `\$\{progress\}%` \}\}/);
});

test("shared progress shimmer is clipped to the filled region and honors reduced motion", async () => {
    const component = await readFile(
        new URL("../../resources/js/Components/SubmissionTracking/SubmissionTrackingProgress.jsx", import.meta.url),
        "utf8",
    );
    const styles = await readFile(
        new URL("../../resources/css/app.css", import.meta.url),
        "utf8",
    );
    assert.match(component, /relative h-full overflow-hidden rounded-full bg-gradient-to-r from-green-800 via-emerald-700 to-blue-800/);
    assert.match(styles, /@keyframes submission-tracking-progress-sweep[\s\S]*?from \{ transform: translateX\(-140%\); \}[\s\S]*?to \{ transform: translateX\(420%\); \}/);
    assert.match(styles, /prefers-reduced-motion: reduce\)[\s\S]*?\.submission-tracking-progress__sweep\s*\{\s*animation: none;/);
});

test("non-MOV progress keeps its source routing value and zero remains hidden", () => {
    assert.deepEqual(
        progressMeasureForSubmission({ routing: { processing_percentage: 55 } }),
        { label: "Routing Progress", value: 55 },
    );
    assert.equal(
        progressMeasureForSubmission({ routing: { processing_percentage: 0 } }),
        null,
    );
});

test("timeline exposes the next step and dynamically counts the remaining hidden steps", () => {
    const steps = [
        { key: "done", status: "completed" },
        { key: "now", status: "current" },
        ...Array.from({ length: 10 }, (_, index) => ({ key: `future-${index}`, status: "pending" })),
    ];
    const collapsed = timelinePresentation(steps);
    assert.deepEqual(collapsed.visibleSteps.map(({ key }) => key), ["done", "now", "future-0"]);
    assert.equal(collapsed.hiddenSteps.length, 9);
    assert.equal(collapsed.hasToggle, true);

    const expanded = timelinePresentation(steps, true);
    assert.equal(expanded.visibleSteps.length, steps.length);
    assert.equal(expanded.hiddenSteps.length, 0);
    assert.equal(timelinePresentation(steps.slice(0, 2)).hasToggle, false);
});

test("shared modal footer and timeline controls keep existing guarded action handlers", async () => {
    const page = await readFile(
        new URL("../../resources/js/Pages/SubmissionTracking/Index.jsx", import.meta.url),
        "utf8",
    );
    const modal = await readFile(
        new URL("../../resources/js/Components/Crud/CrudDetailsModal.jsx", import.meta.url),
        "utf8",
    );
    const pambTimeline = await readFile(
        new URL("../../resources/js/Components/SubmissionTracking/PambRoutingTimeline.jsx", import.meta.url),
        "utf8",
    );
    const genericTimeline = await readFile(
        new URL("../../resources/js/Components/SubmissionTracking/DocumentRoutingTimeline.jsx", import.meta.url),
        "utf8",
    );
    const history = await readFile(
        new URL("../../resources/js/Components/SubmissionTracking/SubmissionReviewHistory.jsx", import.meta.url),
        "utf8",
    );

    assert.match(modal, /footerActions = null/);
    assert.match(modal, /\{footerActions\}/);
    assert.match(page, /canCorrectSubmissionRouting && details && <Button[^>]*onClick=\{\(\) => openCorrection\(details\)\}/);
    assert.match(page, /onClick=\{\(\) => setReviewHistoryRecord\(details\)\}/);
    assert.match(page, /open=\{Boolean\(reviewHistoryRecord\)\}/);
    assert.match(page, /onClose=\{\(\) => setReviewHistoryRecord\(null\)\}/);
    assert.match(page, /closeOnEscape=\{!reviewHistoryRecord\}/);
    assert.match(page, /Full timeline/);
    assert.match(page, /expandAll=\{expandFullTimeline\}/);
    assert.match(page, /onExpandAllChange=\{setExpandFullTimeline\}/);
    assert.match(page, /onClick=\{\(\) => openAdminOverride\(details\)\}/);
    assert.match(page, /canAdminRoutingOverride && <Button/);
    assert.doesNotMatch(page, /\bdarkTheme\s*\n\s*open=\{Boolean\(showFullDetails/);
    assert.match(page, /bg-gray-50 p-4 dark:border-slate-700 dark:bg-slate-900\/80/);
    assert.match(page, /aria-label="Routing progress and current official document"/);
    assert.match(page, /Routing Progress/);
    assert.match(page, /Current Official Document/);
    assert.match(page, /hideRoutingHistory/);
    assert.match(pambTimeline, /Show \$\{hiddenSteps\.length\} more steps/);
    assert.match(genericTimeline, /Show \$\{hiddenSteps\.length\} more steps/);
    assert.doesNotMatch(pambTimeline, /onReviewHistory|View Review History/);
    assert.match(pambTimeline, /onClick=\{\(\) => setShowRemainingSteps\(\(shown\) => !shown\)\}/);
    assert.match(genericTimeline, /onClick=\{\(\) => setShowRemainingSteps\(\(shown\) => !shown\)\}/);
    assert.match(history, /row\?\.mov_processing\?\.applicable/);
    assert.match(history, /row\?\.routing\?\.routing_history/);
    assert.match(history, /MOV Review History/);
    assert.match(history, /Routing Corrections and Overrides/);
    assert.match(genericTimeline, /!hideRoutingHistory && \(routing\.routing_history/);
    assert.doesNotMatch(page, /pamb-cenro-review-history|document-routing-history/);
});
