<?php

use App\Services\AwsMonthlySummaryXlsxService;

require_once __DIR__.'/../Support/IsolatedExportStorage.php';

beforeEach(function (): void { isolateGeneratedExportStorage(); });
afterEach(function (): void { removeIsolatedGeneratedExportStorage(); });

test('an exception while building an export removes its temporary archive', function (): void {
    $rows = (static function (): Generator {
        throw new RuntimeException('Synthetic row failure.');
        yield [];
    })();

    expect(fn () => app(AwsMonthlySummaryXlsxService::class)->download($rows, 'September 2026', 'aws-summary.xlsx'))
        ->toThrow(RuntimeException::class, 'Synthetic row failure.');

    expect(glob(storage_path('app').'/*.tmp') ?: [])->toBeEmpty();
});
