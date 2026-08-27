<?php

namespace App\Http\Controllers;

use App\Models\IpafAccountingStatus;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\IpafRevenueTarget;
use App\Models\ProtectedArea;
use App\Services\IpafBankBalanceSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class IpafController extends Controller
{
    public function index(Request $request): Response
    {
        $protectedAreas = ProtectedArea::query()->orderBy('name')->get(['id', 'name']);
        $revenueQuery = IpafRevenueCollection::query()->with('protectedArea:id,name')
            ->when($request->filled('revenue_search'), fn ($q) => $this->search($q, (string) $request->input('revenue_search')))
            ->when($request->filled('revenue_protected_area_id'), fn ($q) => $q->where('protected_area_id', $request->integer('revenue_protected_area_id')))
            ->when($request->filled('revenue_month'), fn ($q) => $q->where('reporting_month', $request->integer('revenue_month')))
            ->when($request->filled('revenue_year'), fn ($q) => $q->where('reporting_year', $request->integer('revenue_year')));
        $totalCollected = IpafRevenueCollection::normalizeMoney((string) (clone $revenueQuery)->sum('total_collected'));
        $revenues = (clone $revenueQuery)->latest('id')->paginate(10, ['*'], 'revenue_page')->withQueryString()->through(fn ($row) => $this->data($row, 'ipaf.revenue.mov'));
        $management = IpafManagementReport::query()->with('protectedArea:id,name')
            ->when($request->filled('management_search'), fn ($q) => $this->search($q, (string) $request->input('management_search')))
            ->when($request->filled('management_protected_area_id'), fn ($q) => $q->where('protected_area_id', $request->integer('management_protected_area_id')))
            ->latest('id')->paginate(10, ['*'], 'management_page')->withQueryString()->through(fn ($row) => $this->data($row, 'ipaf.management.mov'));
        $quarterly = $this->quarterlyRevenueSummary($request, $protectedAreas);
        return Inertia::render('Ipaf/Index', [
            'revenueCollections' => $revenues,
            'managementReports' => $management,
            'protectedAreas' => $protectedAreas,
            'filters' => $request->only(['ipaf_tab', 'revenue_search', 'revenue_protected_area_id', 'revenue_month', 'revenue_year', 'management_search', 'management_protected_area_id', 'summary_year', 'summary_quarter', 'summary_protected_area_id', 'accounting_year', 'accounting_protected_area_id', 'analysis_year', 'analysis_protected_area_id']),
            'revenueTotals' => ['total_collected' => $totalCollected, ...IpafRevenueCollection::split($totalCollected)],
            ...$quarterly,
        ]);
    }

    public function storeRevenue(Request $request): RedirectResponse { return $this->persist($request, new IpafRevenueCollection, $this->revenueRules(), 'Revenue Collection', 'ipaf-revenue-movs', 'Revenue collection added successfully.'); }
    public function updateRevenue(Request $request, IpafRevenueCollection $revenueCollection): RedirectResponse { return $this->persist($request, $revenueCollection, $this->revenueRules(), 'Revenue Collection', 'ipaf-revenue-movs', 'Revenue collection updated successfully.'); }
    public function destroyRevenue(IpafRevenueCollection $revenueCollection): RedirectResponse { return $this->destroyRecord($revenueCollection, 'Revenue collection deleted successfully.'); }
    public function revenueMov(IpafRevenueCollection $revenueCollection): BinaryFileResponse { return $this->mov($revenueCollection); }
    public function updateRevenueTargets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'reporting_year' => ['required', 'integer', 'between:2000,2100'],
            'targets' => ['required', 'array'],
            'targets.1' => ['nullable', 'decimal:0,2', 'min:0'],
            'targets.2' => ['nullable', 'decimal:0,2', 'min:0'],
            'targets.3' => ['nullable', 'decimal:0,2', 'min:0'],
            'targets.4' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);
        $userId = $request->user()->id;
        DB::transaction(function () use ($data, $userId): void {
            foreach (range(1, 4) as $quarter) {
                $amount = data_get($data, "targets.$quarter");
                $key = ['protected_area_id' => $data['protected_area_id'], 'reporting_year' => $data['reporting_year'], 'quarter' => $quarter];
                if ($amount === null || $amount === '') {
                    IpafRevenueTarget::query()->where($key)->delete();
                    continue;
                }
                $target = IpafRevenueTarget::query()->firstOrNew($key);
                $target->fill(['target_amount' => $amount, 'updated_by' => $userId]);
                if (! $target->exists) $target->created_by = $userId;
                $target->save();
            }
        });
        return back()->with('success', 'Quarterly revenue targets updated successfully.');
    }
    public function updateAccountingStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'reporting_year' => ['required', 'integer', 'between:2000,2100'],
            'total_ipaf_collection' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'bank_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'status_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $userId = $request->user()->id;
        $status = IpafAccountingStatus::query()->firstOrNew([
            'protected_area_id' => $data['protected_area_id'],
            'reporting_year' => $data['reporting_year'],
        ]);
        $wasExisting = $status->exists;
        $syncedAccounting = $status->exists
            && $status->accounting_data_source === 'Google Sheets'
            && $status->bank_balance_sync_status === 'synced';
        $bankBalance = $syncedAccounting ? $status->bank_balance : $data['bank_balance'];
        $totalCollection = $syncedAccounting ? $status->total_ipaf_collection : $data['total_ipaf_collection'];
        $status->fill([
            'total_ipaf_collection' => $totalCollection,
            'bank_balance' => $bankBalance,
            'status_note' => $data['status_note'] ?? null,
            'updated_by' => $userId,
        ]);
        if (! $status->exists) $status->created_by = $userId;
        $status->save();
        $redirectParameters = [
            'ipaf_tab' => 'accounting',
            'accounting_year' => (int) $data['reporting_year'],
        ];
        if ($request->filled('accounting_protected_area_id')) {
            $redirectParameters['accounting_protected_area_id'] = $request->integer('accounting_protected_area_id');
        }
        return redirect()->route('ipaf.index', $redirectParameters)
            ->with('success', $wasExisting ? 'IPAF accounting data updated successfully.' : 'IPAF accounting data added successfully.');
    }
    public function syncAccountingBankBalances(Request $request, IpafBankBalanceSyncService $syncService): RedirectResponse
    {
        abort_unless($request->user()?->can('technical-reports.update'), 403);
        $data = $request->validate([
            'reporting_year' => ['required', 'integer', 'between:2000,2100'],
            'accounting_protected_area_id' => ['nullable', 'integer', 'exists:protected_areas,id'],
        ]);
        $redirectParameters = [
            'ipaf_tab' => 'accounting',
            'accounting_year' => (int) $data['reporting_year'],
        ];
        if (! empty($data['accounting_protected_area_id'])) {
            $redirectParameters['accounting_protected_area_id'] = (int) $data['accounting_protected_area_id'];
        }

        try {
            $result = $syncService->sync((int) $data['reporting_year']);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('ipaf.index', $redirectParameters)
                ->withErrors(['bank_balance_sync' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            return redirect()->route('ipaf.index', $redirectParameters)
                ->withErrors(['bank_balance_sync' => 'Accounting data could not be synchronized because the official Google Sheet could not be read or validated. Existing values were retained.']);
        }

        $created = count($result['created']);
        $changed = count($result['updated']);
        $unchanged = count($result['unchanged']);
        $excluded = count($result['excluded']);
        $unmapped = count($result['unmapped']);
        $sourceDate = Carbon::parse($result['source_as_of'])->format('M j, Y');
        $message = "IPAF Accounting data synchronization completed for the {$sourceDate} source snapshot. {$created} created, {$changed} updated, {$unchanged} unchanged";
        if ($excluded > 0) $message .= ", {$excluded} excluded PA skipped";
        if ($unmapped > 0) $message .= ", {$unmapped} unmapped PA skipped";

        return redirect()->route('ipaf.index', $redirectParameters)
            ->with('success', $message.'.')
            ->with('ipaf_bank_balance_sync_result', $result);
    }
    public function storeManagement(Request $request): RedirectResponse { return $this->persist($request, new IpafManagementReport, $this->managementRules(), 'Management of Integrated Area Protected Area Fund (IPAF)', 'ipaf-management-movs', 'Management of IPAF report added successfully.'); }
    public function updateManagement(Request $request, IpafManagementReport $managementReport): RedirectResponse { return $this->persist($request, $managementReport, $this->managementRules(), 'Management of Integrated Area Protected Area Fund (IPAF)', 'ipaf-management-movs', 'Management of IPAF report updated successfully.'); }
    public function destroyManagement(IpafManagementReport $managementReport): RedirectResponse { return $this->destroyRecord($managementReport, 'Management of IPAF report deleted successfully.'); }
    public function managementMov(IpafManagementReport $managementReport): BinaryFileResponse { return $this->mov($managementReport); }

    private function persist(Request $request, Model $record, array $rules, string $activity, string $folder, string $message): RedirectResponse
    {
        $data = $request->validate($rules); $exists = $record->exists; $old = $record->mov_file_path; $new = null; $replace = $request->boolean('delete_mov') || $request->hasFile('mov');
        try {
            if ($request->hasFile('mov')) { $file = $request->file('mov'); $new = $file->store($folder, 'public'); if (! is_string($new)) throw new RuntimeException('The MOV could not be stored.'); $data = [...$data, 'mov_file_name' => $file->getClientOriginalName(), 'mov_file_path' => $new, 'mov_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'mov_size' => $file->getSize()]; }
            elseif ($request->boolean('delete_mov')) $data = [...$data, 'mov_file_name' => null, 'mov_file_path' => null, 'mov_mime_type' => null, 'mov_size' => null];
            unset($data['mov'], $data['delete_mov']); $data['activity_name'] = $activity; $data['updated_by'] = $request->user()->id; if (! $exists) $data['created_by'] = $request->user()->id;
            DB::transaction(fn () => $exists ? $record->update($data) : $record::create($data));
        } catch (Throwable $e) { if ($new) Storage::disk('public')->delete($new); throw $e; }
        if ($replace && $old) Storage::disk('public')->delete($old);
        return back()->with('success', $message);
    }
    private function destroyRecord(Model $record, string $message): RedirectResponse { $path = $record->mov_file_path; DB::transaction(fn () => $record->delete()); if ($path) Storage::disk('public')->delete($path); return back()->with('success', $message); }
    private function mov(Model $record): BinaryFileResponse { abort_unless($record->mov_file_path && Storage::disk('public')->exists($record->mov_file_path), 404); return response()->file(Storage::disk('public')->path($record->mov_file_path)); }
    private function search($query, string $search): void { $search = trim($search); $query->where(fn ($q) => $q->where('target_office', 'like', "%{$search}%")->orWhere('activity_name', 'like', "%{$search}%")->orWhere('document_type', 'like', "%{$search}%")->orWhereHas('protectedArea', fn ($q) => $q->where('name', 'like', "%{$search}%"))); }
    private function commonRules(): array { return ['protected_area_id' => ['required', 'exists:protected_areas,id'], 'target_office' => ['required', 'string', 'max:255'], 'document_type' => ['required', Rule::in(['Final Report', 'Progress Report'])], 'date_report_released_cenro' => ['nullable', 'date'], 'date_received_penro' => ['nullable', 'date'], 'date_endorsed_regional' => ['nullable', 'date'], 'mov' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'], 'delete_mov' => ['nullable', 'boolean'], 'remarks' => ['nullable', 'string']]; }
    private function revenueRules(): array { return [...$this->commonRules(), 'reporting_month' => ['required', 'integer', 'between:1,12'], 'reporting_year' => ['required', 'integer', 'between:2000,2100'], 'total_collected' => ['required', 'decimal:0,2', 'min:0'], 'deadline_submission' => ['required', 'date']]; }
    private function managementRules(): array { return [...$this->commonRules(), 'date_conducted' => ['nullable', 'string', 'max:255'], 'date_accomplished' => ['nullable', 'date']]; }
    private function data(Model $record, string $movRoute): array { return [...$record->toArray(), 'protected_area_name' => $record->protectedArea?->name, 'mov' => $record->mov_file_path ? ['name' => $record->mov_file_name ?: basename($record->mov_file_path), 'type' => $record->mov_mime_type ?: '', 'size' => $record->mov_size, 'url' => route($movRoute, $record)] : null]; }

    private function quarterlyRevenueSummary(Request $request, $protectedAreas): array
    {
        $year = $request->integer('summary_year') ?: (int) now()->year;
        $quarter = min(4, max(1, $request->integer('summary_quarter') ?: (int) now()->quarter));
        $protectedAreaId = $request->filled('summary_protected_area_id') ? $request->integer('summary_protected_area_id') : null;
        $accountingYear = $request->integer('accounting_year') ?: (int) now()->year;
        $accountingProtectedAreaId = $request->filled('accounting_protected_area_id') ? $request->integer('accounting_protected_area_id') : null;
        $analysisYear = $request->integer('analysis_year') ?: (int) now()->year;
        $analysisProtectedAreaId = $request->filled('analysis_protected_area_id') ? $request->integer('analysis_protected_area_id') : null;
        $firstMonth = (($quarter - 1) * 3) + 1;
        $lastMonth = $firstMonth + 2;

        $collected = IpafRevenueCollection::query()
            ->select('protected_area_id', DB::raw('SUM(total_collected) as quarter_collected'))
            ->where('reporting_year', $year)
            ->whereBetween('reporting_month', [$firstMonth, $lastMonth])
            ->when($protectedAreaId, fn ($query) => $query->where('protected_area_id', $protectedAreaId))
            ->groupBy('protected_area_id')
            ->pluck('quarter_collected', 'protected_area_id');
        $allTargets = IpafRevenueTarget::query()->where('reporting_year', $year)->get();
        $targets = $protectedAreaId ? $allTargets->where('protected_area_id', $protectedAreaId) : $allTargets;
        $quarterTargets = $targets->where('quarter', $quarter)->keyBy('protected_area_id');
        $summaryAreas = $protectedAreas->when($protectedAreaId, fn ($areas) => $areas->where('id', $protectedAreaId))->values();
        $rows = $summaryAreas->map(function ($area) use ($collected, $quarterTargets): array {
            $target = $quarterTargets->get($area->id)?->target_amount;
            $quarterCollected = IpafRevenueCollection::normalizeMoney($collected->get($area->id, '0'));
            return [
                'protected_area_id' => $area->id,
                'protected_area_name' => $area->name,
                'target_amount' => $target,
                'total_collected' => $quarterCollected,
                'percentage_accomplishment' => IpafRevenueCollection::accomplishmentPercentage($quarterCollected, $target),
            ];
        });
        $totalTarget = IpafRevenueCollection::sumMoney($rows->pluck('target_amount')->filter(fn ($value) => $value !== null));
        $totalCollected = IpafRevenueCollection::sumMoney($rows->pluck('total_collected'));
        $annualCollected = IpafRevenueCollection::query()
            ->select('protected_area_id', DB::raw('SUM(total_collected) as annual_collected'))
            ->where('reporting_year', $year)
            ->when($protectedAreaId, fn ($query) => $query->where('protected_area_id', $protectedAreaId))
            ->groupBy('protected_area_id')
            ->pluck('annual_collected', 'protected_area_id');
        $targetsByArea = $targets->groupBy('protected_area_id');
        $annualRows = $summaryAreas->map(function ($area) use ($targetsByArea, $annualCollected): array {
            $areaTargets = $targetsByArea->get($area->id, collect())->keyBy('quarter');
            $annualTarget = $areaTargets->count() === 4
                ? IpafRevenueCollection::sumMoney($areaTargets->pluck('target_amount'))
                : null;
            $collectedAmount = IpafRevenueCollection::normalizeMoney($annualCollected->get($area->id, '0'));
            return [
                'protected_area_id' => $area->id,
                'protected_area_name' => $area->name,
                'annual_target' => $annualTarget,
                'annual_total_collected' => $collectedAmount,
                'percentage_accomplishment' => IpafRevenueCollection::accomplishmentPercentage($collectedAmount, $annualTarget),
            ];
        });
        $annualTargetValues = $annualRows->pluck('annual_target')->filter(fn ($value) => $value !== null);
        $annualTotalTarget = $annualTargetValues->isEmpty() ? null : IpafRevenueCollection::sumMoney($annualTargetValues);
        $annualTotalCollected = IpafRevenueCollection::sumMoney($annualRows->pluck('annual_total_collected'));

        $provincialAccountingRows = IpafAccountingStatus::query()
            ->with('protectedArea:id,name')
            ->where('reporting_year', $accountingYear)
            ->orderBy('protected_area_id')
            ->get()
            ->map(function (IpafAccountingStatus $status): array {
            return [
                'id' => $status->id,
                'protected_area_id' => $status->protected_area_id,
                'protected_area_name' => $status->protectedArea?->name,
                'reporting_year' => $status->reporting_year,
                'total_ipaf_collection' => $status->total_ipaf_collection,
                'bank_balance' => $status->bank_balance,
                'status_note' => $status->status_note,
                'accounting_data_source' => $status->accounting_data_source,
                'total_ipaf_collection_source_reference' => $status->total_ipaf_collection_source_reference,
                'bank_balance_source' => $status->bank_balance_source,
                'bank_balance_source_reference' => $status->bank_balance_source_reference,
                'bank_balance_synced_at' => $status->bank_balance_synced_at?->toIso8601String(),
                'bank_balance_sync_status' => $status->bank_balance_sync_status,
                'bank_balance_source_as_of' => $status->bank_balance_source_as_of?->toDateString(),
                'updated_at' => $status->updated_at?->toIso8601String(),
            ];
        });
        $accountingRows = $accountingProtectedAreaId
            ? $provincialAccountingRows->where('protected_area_id', $accountingProtectedAreaId)->values()
            : $provincialAccountingRows;
        $accountingCollectionValues = $accountingRows->pluck('total_ipaf_collection')->filter(fn ($value) => $value !== null);
        $bankBalanceValues = $accountingRows->pluck('bank_balance')->filter(fn ($value) => $value !== null);
        $provincialAccountingCollectionValues = $provincialAccountingRows->pluck('total_ipaf_collection')->filter(fn ($value) => $value !== null);
        $provincialBankBalanceValues = $provincialAccountingRows->pluck('bank_balance')->filter(fn ($value) => $value !== null);
        $monthlyCollections = IpafRevenueCollection::query()
            ->select('reporting_month', DB::raw('SUM(total_collected) as total_collected'))
            ->where('reporting_year', $analysisYear)
            ->when($analysisProtectedAreaId, fn ($query) => $query->where('protected_area_id', $analysisProtectedAreaId))
            ->groupBy('reporting_month')
            ->pluck('total_collected', 'reporting_month');
        $analysisTargets = IpafRevenueTarget::query()
            ->where('reporting_year', $analysisYear)
            ->when($analysisProtectedAreaId, fn ($query) => $query->where('protected_area_id', $analysisProtectedAreaId))
            ->get();
        $monthlyRevenue = collect(range(1, 12))->map(fn ($month) => [
            'month' => $month,
            'total_collected' => IpafRevenueCollection::normalizeMoney($monthlyCollections->get($month, '0')),
        ]);
        $quarterlyRevenue = collect(range(1, 4))->map(function ($analysisQuarter) use ($analysisTargets, $monthlyCollections): array {
            $firstAnalysisMonth = (($analysisQuarter - 1) * 3) + 1;
            $quarterTargetValues = $analysisTargets->where('quarter', $analysisQuarter)->pluck('target_amount');
            $quarterCollectedValues = collect(range($firstAnalysisMonth, $firstAnalysisMonth + 2))
                ->map(fn ($month) => $monthlyCollections->get($month, '0'));

            return [
                'quarter' => "Q{$analysisQuarter}",
                'target' => $quarterTargetValues->isEmpty() ? null : IpafRevenueCollection::sumMoney($quarterTargetValues),
                'total_collected' => IpafRevenueCollection::sumMoney($quarterCollectedValues),
            ];
        });
        $bankBalances = IpafAccountingStatus::query()
            ->with('protectedArea:id,name')
            ->where('reporting_year', $analysisYear)
            ->when($analysisProtectedAreaId, fn ($query) => $query->where('protected_area_id', $analysisProtectedAreaId))
            ->whereNotNull('bank_balance')
            ->orderBy('protected_area_id')
            ->get()
            ->map(fn (IpafAccountingStatus $row) => ['protected_area_name' => $row->protectedArea?->name, 'bank_balance' => (string) $row->bank_balance]);
        $years = IpafRevenueCollection::query()->distinct()->pluck('reporting_year')
            ->merge(IpafRevenueTarget::query()->distinct()->pluck('reporting_year'))
            ->merge(IpafAccountingStatus::query()->distinct()->pluck('reporting_year'))
            ->push((int) now()->year)->unique()->sortDesc()->values();
        $accountingYears = IpafAccountingStatus::query()->distinct()->pluck('reporting_year')
            ->merge([$accountingYear, (int) now()->year, (int) now()->year - 1, (int) config('ipaf.accounting_sheet.known_source_year')])
            ->filter(fn ($year) => (int) $year >= 2000)
            ->map(fn ($year) => (int) $year)
            ->unique()->sortDesc()->values();

        return [
            'quarterlyRevenueSummary' => [
                'year' => $year,
                'quarter' => $quarter,
                'rows' => $rows,
                'totals' => [
                    'target_amount' => $totalTarget,
                    'total_collected' => $totalCollected,
                    'percentage_accomplishment' => IpafRevenueCollection::accomplishmentPercentage($totalCollected, $totalTarget),
                ],
                'years' => $years,
            ],
            'revenueTargets' => $allTargets->groupBy('protected_area_id')->map(fn ($areaTargets) => $areaTargets->mapWithKeys(fn ($target) => [(string) $target->quarter => $target->target_amount])),
            'annualRevenuePerformance' => [
                'year' => $year,
                'rows' => $annualRows,
                'totals' => [
                    'annual_target' => $annualTotalTarget,
                    'annual_total_collected' => $annualTotalCollected,
                    'percentage_accomplishment' => IpafRevenueCollection::accomplishmentPercentage($annualTotalCollected, $annualTotalTarget),
                ],
            ],
            'accountingStatusSummary' => [
                'year' => $accountingYear,
                'years' => $accountingYears,
                'rows' => $accountingRows,
                'totals' => [
                    'total_ipaf_collection' => $accountingCollectionValues->isEmpty() ? null : $this->sumAccountingMoney($accountingCollectionValues),
                    'bank_balance' => $bankBalanceValues->isEmpty() ? null : $this->sumAccountingMoney($bankBalanceValues),
                ],
                'provincial_totals' => [
                    'has_records' => $provincialAccountingRows->isNotEmpty(),
                    'total_ipaf_collection' => $provincialAccountingCollectionValues->isEmpty() ? null : $this->sumAccountingMoney($provincialAccountingCollectionValues),
                    'bank_balance' => $provincialBankBalanceValues->isEmpty() ? null : $this->sumAccountingMoney($provincialBankBalanceValues),
                ],
            ],
            'ipafAnalysis' => [
                'year' => $analysisYear,
                'years' => $years,
                'monthly_revenue' => $monthlyRevenue,
                'has_monthly_revenue' => $monthlyCollections->isNotEmpty(),
                'quarterly_performance' => $quarterlyRevenue,
                'has_quarterly_performance' => $analysisTargets->isNotEmpty() || $monthlyCollections->isNotEmpty(),
                'bank_balances' => $bankBalances,
            ],
        ];
    }

    private function sumAccountingMoney(iterable $values): string
    {
        $total = '0.00';
        foreach ($values as $value) {
            $total = bcadd($total, (string) $value, 2);
        }

        return $total;
    }
}
