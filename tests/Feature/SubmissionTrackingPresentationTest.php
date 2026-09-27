<?php

test('Submission Tracking compact status uses the canonical current action mapping', function (): void {
    $labels = file_get_contents(base_path('resources/js/Utils/routingLabels.js'));
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($labels)->toContain('compactStatusForAction')
        ->and($labels)->toContain('For Release')
        ->and($labels)->toContain('For Receipt')
        ->and($labels)->toContain('For Forwarding')
        ->and($labels)->toContain('For Recommendation')
        ->and($labels)->toContain('For Approval')
        ->and($tracking)->toContain('const currentActionFor')
        ->and($tracking)->toContain('const actionStatus = compactStatusForAction(currentActionFor(row));');
});

test('Submission Tracking queues prioritize operational columns and server-derived status', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)
        ->toContain('const canonicalStatus = String(routingStatusFor(row) || "");')
        ->toContain('["submission", "office_area", "destination", "status"]')
        ->toContain('["submission", "office_area", "status", "required_action", "deadline_submission"]')
        ->toContain('columns.filter((column) => visibleColumnKeys.includes(column.key))')
        ->toContain('Current Holder / Office')
        ->toContain('Next Expected Action')
        ->toContain('aria-label="Current routing summary"');
});

test('canonical routing action labels distinguish CENRO release from later receipt, forwarding, recommendation, and approval', function (): void {
    $actions = collect(app(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::class)->actionProfile('conservation')['actions'])->keyBy('key');

    expect($actions['forward_to_penro_records']['action_label'])->toBe('Release to PENRO Records')
        ->and($actions['receive_at_penro_records']['action_label'])->toBe('Receive')
        ->and($actions['assign_to_tsd_chief']['action_label'])->toBe('Assign to TSD Chief')
        ->and($actions['recommend_to_office_penro']['action_label'])->toBe('Recommend to Office of the PENRO')
        ->and($actions['approve_for_regional_release']['action_label'])->toBe('Approve for Regional Release');
});

test('shared active routing profiles declare document replacement by semantic operation', function (): void {
    $registry = app(\App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::class);
    $activeSources = ['conservation', 'engp', 'bms', 'bams', 'imea', 'imea-maintenance', 'aws', 'ipaf-management', 'revenue', 'management-plans'];
    foreach ($activeSources as $source) {
        $actions = collect($registry->actionProfile($source, false)['actions'])->keyBy('key');
        expect($actions['receive_at_penro_records']['document_operation'] ?? null)->toBeNull()
            ->and($actions['forward_to_penro_records']['document_operation'] ?? null)->toBe('forward')
            ->and($actions['forward_to_office_penro']['document_operation'] ?? null)->toBe('forward')
            ->and($actions['release_to_regional']['document_operation'] ?? null)->toBe('forward');
    }
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));
    $field = file_get_contents(base_path('resources/js/Components/SubmissionTracking/RoutingAttachmentField.jsx'));
    expect($tracking)->toContain('canReplaceDocument={canReplaceSelectedDocument}')
        ->and($field)->toContain('Current Official Document')
        ->and($field)->toContain('Updated Official Copy (Optional)')
        ->and($field)->toContain('If no new copy is attached, the existing current document will be forwarded.')
        ->and($field)->toContain('canReplaceDocument');
});

test('Full Details separates holder from office and avoids repeating the current routing summary', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));
    $timeline = file_get_contents(base_path('resources/js/Components/SubmissionTracking/DocumentRoutingTimeline.jsx'));

    expect($tracking)
        ->toContain('>Current Holder</dt>')
        ->toContain('>Office</dt>')
        ->toContain('details.routing?.current_location')
        ->toContain('details.routing?.responsible_office')
        ->not->toContain('Current processing context: {details.routing.current_location')
        ->and($timeline)
        ->toContain('title="Routing Activity"')
        ->not->toContain('label="Current Status"')
        ->not->toContain('label="Current Location"');
});

test('shared upload dropzone helper and instructions remain legible in dark mode', function (): void {
    $dropzone = file_get_contents(base_path('resources/js/Components/Attachments/AttachmentDropzone.jsx'));
    $field = file_get_contents(base_path('resources/js/Components/SubmissionTracking/RoutingAttachmentField.jsx'));
    $theme = file_get_contents(base_path('resources/css/app.css'));

    expect($dropzone)
        ->toContain('dark:text-slate-200')
        ->toContain('dark:text-slate-300')
        ->toContain('dark:bg-emerald-950/20')
        ->and($field)
        ->toContain('Updated Official Copy (Optional)')
        ->toContain('dark:text-gray-300')
        ->and($theme)
        ->toContain('[class~="text-gray-500"]:not([class*="dark:text-"])')
        ->toContain('[class~="text-slate-600"]:not([class*="dark:text-"])')
        ->toContain('color: #9ca3af;')
        ->toContain('color: #d1d5db;');
});

test('routing action normalization preserves receive correction before broad correction matching', function (): void {
    $labels = file_get_contents(base_path('resources/js/Utils/routingLabels.js'));
    expect($labels)->toContain("if (/receive\\s+correction/i.test(value)) return 'Receive Correction';");
    $receive = strpos($labels, "if (/receive|receipt/i.test(value)) return 'Receive';");
    $return = strpos($labels, "if (/return|correction/i.test(value)) return 'Return for Correction';");

    expect($receive)->not->toBeFalse()
        ->and($return)->not->toBeFalse()
        ->and($receive)->toBeLessThan($return);
});

test('Submission Tracking labels Super Admin queues as global monitoring without changing operational labels', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)->toContain('incoming: "Incoming Submissions"')
        ->and($tracking)->toContain('outgoing: "Outgoing Submissions"')
        ->and($tracking)->toContain('receive: "Receive"')
        ->and($tracking)->toContain('release: "Release"');
});

test('PAMB MOV release presentation requires the actor-scoped release flag', function (): void {
    $progress = file_get_contents(base_path('resources/js/Components/SubmissionTracking/PambMovProgress.jsx'));

    expect($progress)
        ->toContain('actions.can_release === true')
        ->not->toContain('context.can_release_mov) && row.cenro_release_applicable');
});

test('PAMB receipt actions open the receipt transition instead of the regional release transition', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)
        ->toContain('"receive_at_penro_records"')
        ->toContain('? "penro_receipt"')
        ->toContain('(form.data.stage === "penro_receipt" &&');
});
