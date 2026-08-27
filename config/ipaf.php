<?php

return [
    'accounting_sheet' => [
        'spreadsheet_id' => env(
            'IPAF_ACCOUNTING_SPREADSHEET_ID',
            env('GOOGLE_SHEETS_IPAF_SPREADSHEET_ID', '1CG4LM1V6_nkNVJiEE4a604CXSNgHvFXfivUp5mawgjg'),
        ),
        'sheet_name' => env(
            'IPAF_ACCOUNTING_SHEET_NAME',
            env('GOOGLE_SHEETS_IPAF_SHEET_NAME', 'ALL IPAF'),
        ),
        'heading_prefix' => 'BANK BALANCES AS OF',
        // UI hint only; synchronization still derives and validates the year from the live heading.
        'known_source_year' => (int) env('IPAF_ACCOUNTING_SOURCE_YEAR', 2025),
        'label_range' => 'N6:R6',
        'balance_range' => 'N23:R23',
        'collection_range' => 'B23:F23',
        'heading_range' => 'N5:R5',
        'total_label_range' => 'A23:A23',
        'mapping' => [
            'APL' => 4,
            'BPL' => 6,
            'MHRWS' => 2,
            'PUJADA' => 3,
            'BBPLS/BMSFR' => 7,
        ],
        'blocked' => [],
        'excluded' => ['MPL' => 1],
    ],
];
