<?php

test('submission tracking keeps Incoming Outgoing History in the sidebar and action filters inside Incoming', function (): void {
    $layout = file_get_contents(base_path('resources/js/Layouts/AuthenticatedLayout.jsx'));
    $index = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($layout)->toContain("label: 'CDS-SMART MONITORING'")
        ->and($layout)->toContain("label: 'Submission Tracking'")
        ->and($layout)->toContain("label: 'Incoming', href: '/submission-tracking?view=incoming'")
        ->and($layout)->toContain("label: 'Outgoing', href: '/submission-tracking?view=outgoing'")
        ->and($layout)->toContain("label: 'History', href: '/submission-tracking?view=history'")
        ->and($layout)->not->toContain("['Receive', 'incoming']")
        ->and($layout)->not->toContain("['Forward', 'outgoing']")
        ->and($layout)->not->toContain("['Other Actions', 'other']")
        ->and($layout)->not->toContain("label: 'SUBMISSION TRACKING'")
        ->and($index)->toContain('receive: "Receive"')
        ->and($index)->toContain('forward: "Forward"')
        ->and($index)->toContain('release: "Release"')
        ->and($index)->toContain('decision: "Review / Decision"')
        ->and($index)->toContain('Submission History')
        ->and($index)->toContain('aria-label="Incoming action filters"');
});
