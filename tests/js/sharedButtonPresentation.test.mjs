import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

test("shared buttons preserve variant semantics and apply restrained interaction styling", async () => {
    const component = await readFile(
        new URL("../../resources/js/Components/Button.jsx", import.meta.url),
        "utf8",
    );
    const styles = await readFile(
        new URL("../../resources/css/app.css", import.meta.url),
        "utf8",
    );

    assert.match(component, /const actionGradient = 'bg-gradient-to-b from-green-800 to-green-950 text-white/);
    for (const variantLine of [
        "primary: actionGradient",
        "secondary: `border border-green-950 ${actionGradient}`",
        "danger: `border border-red-800 ${dangerGradient}`",
        "warning: 'border border-orange-800 bg-gradient-to-b from-orange-600 to-orange-900 text-white hover:from-orange-500 hover:to-orange-800'",
        "ghost: actionGradient",
        "cancel: neutralAction",
        "back: neutralAction",
    ]) assert.ok(component.includes(variantLine));
    assert.match(component, /secondary: `border border-green-950 \$\{actionGradient\}`/);
    assert.match(component, /danger: `border border-red-800 \$\{dangerGradient\}`/);
    assert.match(component, /data-cds-action="true" data-cds-action-variant=\{variant\}/);
    assert.match(component, /type=\{type\}[^\n]*\{\.\.\.props\}/);
    assert.match(component, /focus-visible:ring-2/);
    assert.match(styles, /\.cds-button-interaction:not\(:disabled\):not\(\[aria-disabled="true"\]\):hover/);
    assert.match(styles, /\.cds-button-interaction:not\(:disabled\):not\(\[aria-disabled="true"\]\):active/);
    assert.match(styles, /\.cds-button-interaction:disabled,[\s\S]*?transform: none/);
    assert.match(styles, /prefers-reduced-motion: reduce\)[\s\S]*?\.cds-button-interaction,[\s\S]*?\[data-cds-action="true"\]\s*\{\s*transform: none !important;\s*transition: none !important;/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-action-variant="danger"\][\s\S]*?linear-gradient\(180deg, #b91c1c 0%, #f87171 100%\)/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-action-variant="cancel"\]/);
    assert.match(styles, /--cds-selected-tab-gradient:\s*linear-gradient\(105deg, #16a34a 0%, #86efac 100%\)/);
    assert.match(styles, /button\[role="tab"\]\[aria-selected="true"\][\s\S]*?background-image: var\(--cds-selected-tab-gradient\)/);
    assert.match(styles, /nav\[aria-label="Dashboard program tabs"\] button\[aria-current="page"\]/);
});

test("shared modal, confirmation, export, and PAMB action controls retain their handlers through Button", async () => {
    const paths = [
        "../../resources/js/Components/Crud/CrudDetailsModal.jsx",
        "../../resources/js/Components/Crud/CrudFormModal.jsx",
        "../../resources/js/Components/ConfirmDialog.jsx",
        "../../resources/js/Components/CurrentPasswordConfirmDialog.jsx",
        "../../resources/js/Components/SubmissionTracking/PambMovProgress.jsx",
        "../../resources/js/Components/SubmissionTracking/PambRoutingTimeline.jsx",
        "../../resources/js/Components/SubmissionTracking/DocumentRoutingTimeline.jsx",
        "../../resources/js/Components/SubmissionTracking/RoutingAttachmentField.jsx",
        "../../resources/js/Components/SubmissionTracking/RoutingAttachmentLink.jsx",
        "../../resources/js/Pages/AWS/AwsSummaryExportModal.jsx",
        "../../resources/js/Components/SpatialLayerPanel.jsx",
        "../../resources/js/Components/Notifications/NotificationBell.jsx",
        "../../resources/js/Pages/Imea/Index.jsx",
        "../../resources/js/Pages/Admin/Settings/SystemDiagnostics.jsx",
        "../../resources/js/Layouts/AuthenticatedLayout.jsx",
        "../../resources/js/Pages/ManagementPlans/Form.jsx",
        "../../resources/js/Pages/ManagementPlans/Index.jsx",
        "../../resources/js/Pages/ManagementPlans/PlanInformation.jsx",
    ];
    const sources = await Promise.all(paths.map((path) => readFile(new URL(path, import.meta.url), "utf8")));
    const [details, form, confirm, password, pamb, pambTimeline, genericTimeline, attachmentField, attachmentLink, awsExport, spatialLayers, notifications, imea, diagnostics, layout, managementForm, managementIndex, planInformation] = sources;

    assert.match(details, /<Button[^>]*onClick=\{onEdit\}/);
    assert.match(details, /<Button[^>]*onClick=\{onDelete\}/);
    assert.match(details, /<Button[^>]*onClick=\{onClose\}/);
    assert.match(form, /<Button type="submit" size="compact" variant="primary" disabled=\{processing\}/);
    assert.match(form, /<Button[^>]*variant="danger" onClick=\{onDelete\}/);
    assert.match(confirm, /variant="cancel" onClick=\{onCancel\}/);
    assert.match(confirm, /variant=\{variant === 'danger' \? 'danger' : 'primary'\} onClick=\{onConfirm\}/);
    assert.match(password, /<Button type="submit" size="compact" variant="primary" disabled=\{processing \|\| !password\}/);
    assert.match(pamb, /onClick=\{submitReview\} disabled=\{submitting\}/);
    assert.match(pamb, /onClick=\{\(\) => onReview\?\.\(row, 'ready_for_release'\)\}/);
    assert.match(pamb, /onClick=\{\(\) => onRelease\?\.\(row\)\}/);
    assert.match(pambTimeline, /<Button[\s\S]*?onRecord\?\./);
    assert.match(genericTimeline, /onClick=\{\(\) => onAction\?\.\(action\)\}/);
    assert.match(attachmentField, /<Button[^>]*onClick=\{downloadCurrent\}/);
    assert.match(attachmentLink, /<Button[\s\S]*?onClick=\{download\}/);
    assert.match(awsExport, /onClick=\{exportFile\} disabled=\{!selectedFormat \|\| Boolean\(generating\)\}/);
    assert.match(spatialLayers, /<Button[^>]*onClick=\{\(\) => zoomTo\(layer\)\}/);
    assert.match(spatialLayers, /variant="danger" onClick=\{\(\) => setLayerToDelete\(layer\)\}/);
    assert.ok(notifications.includes('<UtilityIconButton'));
    assert.ok(notifications.includes('aria-label="Notifications"'));
    assert.match(notifications, /onClick=\{\(\) => request\('\/notifications\/clear', 'POST'\)\}/);
    assert.doesNotMatch(notifications, /<Button[^>]*Clear Notifications/);
    assert.match(notifications, /onClick=\{\(\) => openNotification\(notification\)\}/);
    assert.doesNotMatch(imea, /<button\b/);
    assert.match(diagnostics, /<Button[^>]*onClick=\{run\}/);
    assert.match(diagnostics, /<Button[^>]*onClick=\{copy\}/);
    assert.match(layout, /<Button[^>]*onClick=\{\(\) => router\.post\('\/logout'\)\}/);
    assert.match(managementForm, /<Button type="submit" size="compact" disabled=\{form\.processing\}/);
    assert.match(managementForm, /data-cds-action="true" data-cds-action-variant="cancel"/);
    assert.match(managementIndex, /const actionClass = 'cds-button-interaction rounded-xl bg-gradient-to-b from-green-800 to-green-950/);
    assert.match(planInformation, /const buttonClass = 'cds-button-interaction rounded-xl bg-gradient-to-b from-green-800 to-green-950/);
});
