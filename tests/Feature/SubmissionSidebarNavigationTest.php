<?php

test('submission tracking navigation uses sidebar-selected incoming outgoing and history views', function (): void {
    $layout = file_get_contents(base_path('resources/js/Layouts/AuthenticatedLayout.jsx'));
    $index = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));

    expect($layout)->toContain("label: 'eDATS MONITORING'")
        ->and($layout)->toContain("label: 'Submission Tracking'")
        ->and($layout)->toContain("label: 'Incoming'")
        ->and($layout)->toContain("label: 'Outgoing'")
        ->and($layout)->toContain("label: 'History'")
        ->and($layout)->not->toContain("label: 'SUBMISSION TRACKING'")
        ->and($index)->toContain('Incoming Submissions')
        ->and($index)->toContain('Outgoing Submissions')
        ->and($index)->toContain('Submission History')
        ->and($index)->not->toContain('role="tablist"')
        ->and($index)->not->toContain('const tabs =');
});