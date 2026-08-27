<?php

use App\Models\IpafAccountingStatus;
use App\Services\IpafAccountingSheetReader;
use App\Models\User;
use App\Services\IpafBankBalanceSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function fakeIpafSheet(bool $fail = false, string $heading = 'BANK BALANCES AS OF MARCH 20, 2025', ?array $balances = null, ?array $collections = null): void
{
    $balances ??= ['1,138,989.25', '70,475.00', '1,062,574.84', '1,505,394.43', '1,992,419.09'];
    $collections ??= ['2,440,995.00', '59,475.60', '1,051,907.16', '3,843,500.07', '4,091,376.32'];
    Http::fake(function (Request $request) use ($fail, $heading, $balances, $collections) {
        if ($fail) return Http::response('Unavailable', 503);
        parse_str($request->toPsrRequest()->getUri()->getQuery(), $query);
        return Http::response(match ($query['range'] ?? '') {
            'N5:R5' => '"'.$heading.'","","","",""',
            'N6:R6' => '"APL","BPL","BBPLS/BMSFR","MHRWS","PUJADA"',
            'N23:R23' => implode(',', array_map(fn ($value) => '"'.$value.'"', $balances)),
            'B23:F23' => implode(',', array_map(fn ($value) => '"'.$value.'"', $collections)),
            'A23:A23' => '"TOTAL:"',
            default => 'Unexpected range',
        }, 200, ['Content-Type' => 'text/csv']);
    });
}

function seedIpafProtectedAreas(): void
{
    $user = User::factory()->create();
    foreach ([1 => 'Mati Protected Landscape (MPL)', 2 => 'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)', 3 => 'Pujada Bay Protected Landscape and Seascape (PBPLS)', 4 => 'Aliwagwag Protected Landscape (APL)', 6 => 'Baganga Protected Landscape', 7 => 'Baganga Mangrove Swamp Forest Reserve'] as $id => $name) {
        DB::table('protected_areas')->insert(['id' => $id, 'name' => $name, 'category' => 'Test', 'municipality' => 'Test', 'province' => 'Test', 'region' => 'Test', 'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}

test('it creates and updates safe mapped 2025 records and is idempotent', function () {
    fakeIpafSheet();
    seedIpafProtectedAreas();
    foreach ([2, 4, 6] as $protectedAreaId) {
        IpafAccountingStatus::create(['protected_area_id' => $protectedAreaId, 'reporting_year' => 2025, 'total_ipaf_collection' => '777.77', 'bank_balance' => '1.00', 'status_note' => 'Preserve me']);
    }

    $recordIds = IpafAccountingStatus::pluck('id', 'protected_area_id');
    $result = app(IpafBankBalanceSyncService::class)->sync(2025);

    expect($result['created'])->toEqualCanonicalizing(['PUJADA', 'BBPLS/BMSFR'])
        ->and($result['updated'])->toEqualCanonicalizing(['APL', 'BPL', 'MHRWS'])
        ->and($result['excluded'])->toBe(['MPL'])
        ->and($result['source_as_of'])->toBe('2025-03-20')
        ->and($result['source_year'])->toBe(2025);
    expect(IpafAccountingStatus::count())->toBe(5);
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance'))->toBe('1138989.25');
    expect(IpafAccountingStatus::where('protected_area_id', 2)->value('bank_balance'))->toBe('1505394.43');
    expect(IpafAccountingStatus::where('protected_area_id', 6)->value('bank_balance'))->toBe('70475.00');
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('total_ipaf_collection'))->toBe('2440995.00');
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('status_note'))->toBe('Preserve me');
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance_source_reference'))->toBe('ALL IPAF!N23');
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('total_ipaf_collection_source_reference'))->toBe('ALL IPAF!B23');
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('id'))->toBe($recordIds[4]);

    $firstSyncedAt = IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance_synced_at');
    $rerun = app(IpafBankBalanceSyncService::class)->sync(2025);
    expect($rerun['updated'])->toBe([])
        ->and($rerun['unchanged'])->toEqualCanonicalizing(['APL', 'BPL', 'MHRWS', 'PUJADA', 'BBPLS/BMSFR'])
        ->and(IpafAccountingStatus::count())->toBe(5)
        ->and(IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance_synced_at')->toString())->toBe($firstSyncedAt->toString());
});

test('it derives the source year from the heading and blocks a mismatched selected year', function () {
    fakeIpafSheet();
    expect(fn () => app(IpafBankBalanceSyncService::class)->sync(2026))
        ->toThrow(InvalidArgumentException::class, 'Select Reporting Year 2025');
    Http::assertSentCount(5);
});

test('it parses a future source date and year without a code change', function () {
    fakeIpafSheet(heading: 'BANK BALANCES AS OF APRIL 15, 2026');

    $source = app(IpafAccountingSheetReader::class)->read();

    expect($source['source_heading'])->toBe('BANK BALANCES AS OF APRIL 15, 2026')
        ->and($source['source_as_of'])->toBe('2026-04-15')
        ->and($source['source_year'])->toBe(2026)
        ->and(collect($source['records'])->pluck('source_label')->all())->toBe(['APL', 'BPL', 'BBPLS/BMSFR', 'MHRWS', 'PUJADA']);
});

test('a source failure retains the existing bank balance', function () {
    fakeIpafSheet(true);
    seedIpafProtectedAreas();
    IpafAccountingStatus::create(['protected_area_id' => 4, 'reporting_year' => 2025, 'total_ipaf_collection' => '777.77', 'bank_balance' => '55.00']);

    expect(fn () => app(IpafBankBalanceSyncService::class)->sync(2025))->toThrow(RuntimeException::class);
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance'))->toBe('55.00');
});

test('an unrecognizable source heading aborts before writing', function () {
    fakeIpafSheet(heading: 'CURRENT BANK BALANCES');
    seedIpafProtectedAreas();
    IpafAccountingStatus::create(['protected_area_id' => 4, 'reporting_year' => 2025, 'total_ipaf_collection' => '777.77', 'bank_balance' => '55.00']);

    expect(fn () => app(IpafBankBalanceSyncService::class)->sync(2025))
        ->toThrow(RuntimeException::class, 'heading or AS OF date');
    expect(IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance'))->toBe('55.00');
});

test('an invalid mapped balance is skipped without erasing the stored value', function () {
    fakeIpafSheet(balances: ['', '70,475.00', '1,062,574.84', '1,505,394.43', '1,992,419.09']);
    seedIpafProtectedAreas();
    IpafAccountingStatus::create(['protected_area_id' => 4, 'reporting_year' => 2025, 'total_ipaf_collection' => '777.77', 'bank_balance' => '55.00', 'status_note' => 'Keep this']);

    $result = app(IpafBankBalanceSyncService::class)->sync(2025);

    expect($result['invalid'])->toBe(['APL'])
        ->and(IpafAccountingStatus::where('protected_area_id', 4)->value('bank_balance'))->toBe('55.00')
        ->and(IpafAccountingStatus::where('protected_area_id', 4)->value('total_ipaf_collection'))->toBe('777.77')
        ->and(IpafAccountingStatus::where('protected_area_id', 4)->value('status_note'))->toBe('Keep this');
});
