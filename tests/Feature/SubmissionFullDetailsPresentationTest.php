<?php

test('submission full details hides absent optional values and wraps long routing values', function (): void {
    $index = file_get_contents(base_path('resources/js/Pages/SubmissionTracking/Index.jsx'));
    $service = file_get_contents(base_path('app/Services/SubmissionTracking/SubmissionTrackingService.php'));
    $summary = file_get_contents(base_path('resources/js/Components/Crud/CrudSummaryGrid.jsx'));
    $timeline = file_get_contents(base_path('resources/js/Components/SubmissionTracking/DocumentRoutingTimeline.jsx'));

    expect($index)->toContain('value: details.protected_area')
        ->and($index)->toContain('value: details.reporting_period')
        ->and($service)->toContain('workspaceQueues')
        ->and($index)->not->toContain('value: details.protected_area || FALLBACK')
        ->and($index)->not->toContain('value: details.reporting_period || FALLBACK')
        ->and($summary)->toContain('break-words')
        ->and($summary)->not->toContain('item.value ?? FALLBACK')
        ->and($timeline)->toContain('break-words')
        ->and($timeline)->not->toContain('event.from || FALLBACK');
});