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

test('routing action normalization preserves receive correction and recipient-specific return labels', function (): void {
    $labels = file_get_contents(base_path('resources/js/Utils/routingLabels.js'));
    expect($labels)->toContain("if (/receive\\s+correction/i.test(value)) return 'Receive Correction';");
    $receive = strpos($labels, "if (/receive|receipt/i.test(value)) return 'Receive';");
    $specificReturn = strpos($labels, "if (/^return(?:ed)?\\s+(?:(?:report|document)\\s+)?to\\b|^return\\s+to\\s+previous\\b/i.test(value)) return value;");
    $return = strpos($labels, "if (/return|correction/i.test(value)) return 'Return for Correction';");

    expect($receive)->not->toBeFalse()
        ->and($specificReturn)->not->toBeFalse()
        ->and($return)->not->toBeFalse()
        ->and($receive)->toBeLessThan($specificReturn)
        ->and($specificReturn)->toBeLessThan($return);
});

test('Submission Tracking labels Super Admin queues as global monitoring without changing operational labels', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)->toContain('incoming: "Incoming Submissions"')
        ->and($tracking)->toContain('outgoing: "Outgoing Submissions"')
        ->and($tracking)->toContain('receive: "Receive"')
        ->and($tracking)->toContain('release: "Release"');
});

test('PAMB receipt actions open the receipt transition instead of the regional release transition', function (): void {
    $tracking = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($tracking)
        ->toContain('"receive_at_penro_records"')
        ->toContain('? "penro_receipt"')
        ->toContain('(form.data.stage === "penro_receipt" &&');
});
