<?php

use Illuminate\Support\Facades\File;

test('submission tracking uses the browser local calendar date for native date inputs', function () {
    $helper = File::get(resource_path('js/Utils/dateInput.js'));
    $page = File::get(resource_path('js/Pages/SubmissionTracking/Index.jsx'));

    expect($helper)->toContain('getFullYear()')
        ->and($helper)->toContain('getMonth() + 1')
        ->and($helper)->toContain('getDate()')
        ->and($helper)->not->toContain('toISOString()')
        ->and($page)->toContain('localDateInputValue()')
        ->and($page)->not->toContain('new Date().toISOString().slice(0, 10)');
});

test('submission tracking uses a reusable premium picker only for editable internal event times', function () {
    $page = File::get(resource_path('js/Pages/SubmissionTracking/Index.jsx'));
    $component = File::get(resource_path('js/Components/PremiumTimePicker.jsx'));

    expect($page)->toContain('<PremiumTimePicker')
        ->and($page)->not->toContain('type="datetime-local"')
        ->and($page)->toContain('Event time: recorded automatically when saved.')
        ->and($component)->toContain('type="hidden"')
        ->and($component)->toContain('aria-label={ariaLabel}')
        ->and($component)->toContain('AM or PM wheel')
        ->and($component)->toContain('Cancel')
        ->and($component)->toContain('Done');
});
