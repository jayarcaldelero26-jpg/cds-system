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
