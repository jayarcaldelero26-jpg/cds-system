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
    const [dashboard, bms, bams, meaTabs, settingsShell] = await Promise.all([
        "../../resources/js/Pages/Dashboard.jsx",
        "../../resources/js/Pages/Bms/Index.jsx",
        "../../resources/js/Pages/Bams/Index.jsx",
        "../../resources/js/Pages/Imea/WorkflowTabs.jsx",
        "../../resources/js/Components/Admin/SettingsShell.jsx",
    ].map((path) => readFile(new URL(path, import.meta.url), "utf8")));

    assert.match(component, /const actionGradient = 'bg-gradient-to-b from-green-800 to-green-950 text-white/);
    for (const variantLine of [
        "primary: actionGradient",
        "secondary: secondaryAction",
        "danger: `border border-red-800 ${dangerGradient}`",
        "warning: 'border border-orange-900 bg-gradient-to-b from-orange-700 to-orange-900 text-white hover:from-orange-800 hover:to-orange-950'",
        "ghost: ghostAction",
        "cancel: neutralAction",
        "back: neutralAction",
    ]) assert.ok(component.includes(variantLine));
    assert.match(component, /secondary: secondaryAction/);
    assert.match(component, /danger: `border border-red-800 \$\{dangerGradient\}`/);
    assert.match(component, /const neutral = \['secondary', 'ghost', 'cancel', 'back'\]/);
    assert.match(component, /data-cds-action="true" data-cds-action-variant=\{variant\} data-cds-size=\{size\}/);
    assert.match(component, /type=\{type\}[^\n]*\{\.\.\.props\}/);
    assert.match(component, /compact: 'min-h-8 rounded-ui px-2\.5 py-1 text-xs'/);
    assert.match(component, /focus-visible:ring-2/);
    assert.match(styles, /\.cds-button-interaction:not\(:disabled\):not\(\[aria-disabled="true"\]\):hover/);
    assert.match(styles, /\.cds-button-interaction:not\(:disabled\):not\(\[aria-disabled="true"\]\):active/);
    assert.match(styles, /\.cds-button-interaction:disabled,[\s\S]*?transform: none/);
    assert.match(styles, /prefers-reduced-motion: reduce\)[\s\S]*?\.cds-button-interaction,[\s\S]*?\[data-cds-action="true"\]\s*\{\s*transform: none !important;\s*transition: none !important;/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-action-variant="danger"\][\s\S]*?linear-gradient\(180deg, #991b1b 0%, #7f1d1d 100%\)/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-action-variant="secondary"\][\s\S]*?background-image: none;[\s\S]*?color: #14532d;/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-action-variant="ghost"\][\s\S]*?background-image: none;[\s\S]*?color: #166534;/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-size="prominent"\][\s\S]*?min-height: 44px;/);
    assert.match(styles, /\[data-cds-action="true"\]\[data-cds-action-variant="cancel"\],[\s\S]*?background-image: none;[\s\S]*?color: #374151;/);
    assert.match(styles, /\.dark \[data-cds-action="true"\]\[data-cds-action-variant="cancel"\][\s\S]*?background-color: #1f2937;[\s\S]*?color: #f3f4f6;/);
    assert.match(styles, /--cds-selected-tab-gradient:\s*linear-gradient\(90deg, #15803d 0%, #1e3a8a 100%\)/);
    assert.match(styles, /--cds-selected-tab-color:\s*#ffffff/);
    assert.match(styles, /button\[role="tab"\]\[aria-selected="true"\][\s\S]*?background-image: var\(--cds-selected-tab-gradient\)/);
    assert.match(styles, /nav\[aria-label="Dashboard program tabs"\] button\[aria-current="page"\]/);
    assert.match(styles, /\.cds-submission-tracking-nav-tab\[aria-current="page"\][\s\S]*?background-image: var\(--cds-selected-tab-gradient\)/);
    assert.match(dashboard, /aria-pressed=\{active === tab\}/);
    assert.match(dashboard, /active === tab \? 'cds-tab-active'/);
    assert.match(bms, /role="group" aria-label="BMS views"/);
    assert.match(bms, /aria-pressed=\{activeTab === 'threats'\}[\s\S]*?'cds-tab-active shadow-md'/);
    assert.match(bams, /role="group" aria-label="BAMS views"/);
    assert.match(bams, /aria-pressed=\{activeTab === 'map'\}[\s\S]*?'cds-tab-active shadow-md'/);
    assert.match(meaTabs, /active \? 'cds-tab-active shadow-sm'/);
    assert.match(settingsShell, /selected \? 'cds-tab-active border-white\/30'/);
});

test("shared modal, confirmation, export, and PAMB action controls retain their handlers through Button", async () => {
    const paths = [
        "../../resources/js/Components/Crud/CrudDetailsModal.jsx",
        "../../resources/js/Components/Crud/CrudFormModal.jsx",
        "../../resources/js/Pages/SubmissionTracking/Index.jsx",
        "../../resources/js/Components/ConfirmDialog.jsx",
        "../../resources/js/Components/CurrentPasswordConfirmDialog.jsx",
        "../../resources/js/Components/SubmissionTracking/PambMovActions.jsx",
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
    const [details, form, submissionTracking, confirm, password, pamb, pambTimeline, genericTimeline, attachmentField, attachmentLink, awsExport, spatialLayers, notifications, imea, diagnostics, layout, managementForm, managementIndex, planInformation] = sources;

    assert.match(details, /<Button[^>]*onClick=\{onEdit\}/);
    assert.match(details, /<Button[^>]*onClick=\{onDelete\}/);
    assert.match(details, /<Button[^>]*onClick=\{onClose\}/);
    assert.match(form, /saveVariant = 'primary'/);
    assert.match(form, /<Button type="submit" size="compact" variant=\{saveVariant\} disabled=\{processing\}/);
    assert.match(submissionTracking, /saveVariant=\{genericAction\?\.correction \? "warning" : "primary"\}/);
    assert.match(submissionTracking, /reviewForm\.data\.decision === "needs_correction" \? "warning" : "primary"/);
    assert.match(submissionTracking, /current_document\.can_preview \? <Button[^>]*variant="cancel"/);
    assert.match(submissionTracking, /data-cds-action-variant="cancel"[\s\S]*?Preview MOV \/ Report/);
    assert.match(submissionTracking, /selected === category \? "cds-tab-active/);
    assert.match(submissionTracking, /selected === null \? "cds-tab-active/);
    assert.match(form, /<Button[^>]*variant="danger" onClick=\{onDelete\}/);
    assert.match(confirm, /variant="cancel" onClick=\{onCancel\}/);
    assert.match(confirm, /variant=\{variant === 'danger' \? 'danger' : 'primary'\} onClick=\{onConfirm\}/);
    assert.match(password, /<Button type="submit" size="compact" variant="primary" disabled=\{processing \|\| !password\}/);
    assert.match(pamb, /onClick=\{submitReview\} disabled=\{submitting\}/);
    assert.match(pamb, /onClick=\{\(\) => onReview\?\.\(row, 'ready_for_release'\)\}/);
    assert.match(pamb, /variant="warning" onClick=\{\(\) => onReview\?\.\(row, 'needs_correction'\)\}/);
    assert.match(pamb, /onClick=\{\(\) => onRelease\?\.\(row\)\}/);
    assert.match(pambTimeline, /<Button[\s\S]*?onRecord\?\./);
    assert.match(pambTimeline, /variant=\{action\.correction \? "warning" : "primary"\}/);
    assert.match(genericTimeline, /onClick=\{\(\) => onAction\?\.\(action\)\}/);
    assert.match(genericTimeline, /variant=\{action\.correction \? "warning" : "primary"\}/);
    assert.match(attachmentField, /<Button[^>]*onClick=\{downloadCurrent\}/);
    assert.match(attachmentLink, /<Button[\s\S]*?onClick=\{download\}/);
    assert.match(awsExport, /onClick=\{exportFile\} disabled=\{!selectedFormat \|\| Boolean\(generating\)\}/);
    assert.match(spatialLayers, /<Button[^>]*onClick=\{\(\) => zoomTo\(layer\)\}/);
    assert.match(spatialLayers, /variant="danger" onClick=\{\(\) => setLayerToDelete\(layer\)\}/);
    assert.ok(notifications.includes('<UtilityIconButton'));
    assert.ok(notifications.includes('aria-label="Notifications"'));
    assert.match(notifications, /onClear=\{\(\) => request\('\/notifications\/clear', 'POST'\)\}/);
    assert.doesNotMatch(notifications, /<Button[^>]*Clear Notifications/);
    assert.match(notifications, /onClick=\{\(\) => onOpen\(notification\)\}/);
    assert.doesNotMatch(imea, /<button\b/);
    assert.match(diagnostics, /<Button[^>]*onClick=\{run\}/);
    assert.match(diagnostics, /<Button[^>]*onClick=\{copy\}/);
    assert.match(layout, /<Button[^>]*onClick=\{\(\) => router\.post\('\/logout'\)\}/);
    assert.match(layout, /isTrackingViewLink = child\.href\?\.startsWith\('\/submission-tracking\?view='/);
    assert.match(layout, /cds-submission-tracking-nav-tab cds-tab-active border-l-2/);
    assert.match(managementForm, /<Button type="submit" size="compact" disabled=\{form\.processing\}/);
    assert.match(managementForm, /data-cds-action="true" data-cds-action-variant="cancel"/);
    assert.match(managementIndex, /const actionClass = 'cds-button-interaction rounded-xl bg-gradient-to-b from-green-800 to-green-950/);
    assert.match(planInformation, /const buttonClass = 'cds-button-interaction rounded-xl bg-gradient-to-b from-green-800 to-green-950/);
});
