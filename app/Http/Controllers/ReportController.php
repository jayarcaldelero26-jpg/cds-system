<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Reports\ExecutiveReportExportService;
use App\Services\Reports\ExecutiveReportService;

class ReportController extends Controller
{
    public function __construct(private readonly ExecutiveReportService $reports) {}

    public function index(Request $request): Response
    {
        $report = $this->reports->report($this->validatedFilters($request));

        return Inertia::render('Reports/Index', [
            'report' => $report,
            'canExport' => $request->user()->can('reports.export'),
        ]);
    }

    public function export(Request $request, string $format, ExecutiveReportExportService $exports)
    {
        return $exports->download($this->reports->report($this->validatedFilters($request), includeDetails: $format === 'xlsx'), $format);
    }

    /** @return array<string,mixed> */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'period' => ['nullable', 'string', 'max:100'],
            'domain' => ['nullable', 'in:all,pa,engp'],
            'office' => ['nullable', 'string', 'max:150'],
            'protected_area_id' => ['nullable', 'integer'],
            'workflow' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }
}
