<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Models\TechnicalReport;
use App\Services\Compliance\ComplianceMovService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TechnicalReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'protected_area_id', 'target_office', 'semester', 'year']);
        $reports = TechnicalReport::query()
            ->with('protectedArea:id,name')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(function ($query) use ($search): void {
                    $query->where('target_office', 'like', "%{$search}%")
                        ->orWhere('activity_name', 'like', "%{$search}%")
                        ->orWhere('report_type', 'like', "%{$search}%")
                        ->orWhere('recommendations', 'like', "%{$search}%")
                        ->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('protected_area_id'), fn ($query) => $query->where('protected_area_id', $request->integer('protected_area_id')))
            ->when($request->filled('target_office'), fn ($query) => $query->where('target_office', $request->input('target_office')))
            ->when($request->filled('semester'), fn ($query) => $query->where('semester', $request->input('semester')))
            ->when($request->filled('year'), fn ($query) => $query->whereYear('date_accomplished', $request->integer('year')))
            ->latest()
            ->paginate(10)
            ->withQueryString()
            ->through(fn (TechnicalReport $report) => $this->reportData($report));

        return Inertia::render('TechnicalReports/Index', [
            'technicalReports' => $reports,
            'filters' => $filters,
            ...$this->formOptions(),
            'targetOffices' => TechnicalReport::query()->whereNotNull('target_office')->distinct()->orderBy('target_office')->pluck('target_office'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('TechnicalReports/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules(requireMov: true));
        $storedPath = null;

        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $storedPath = $file->store('technical-reports', 'public');
                if (! is_string($storedPath)) {
                    throw new RuntimeException('The attachment could not be stored.');
                }
                $validated['attachment'] = $storedPath;
                $validated['attachment_original_name'] = $file->getClientOriginalName();
                $validated['attachment_mime_type'] = $file->getMimeType() ?: $file->getClientMimeType();
                $validated['attachment_size'] = $file->getSize();
            }

            DB::transaction(fn () => TechnicalReport::create([
                ...$this->persistenceData($validated),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $exception;
        }

        return to_route('technical-reports.index')->with('success', 'General report created successfully.');
    }

    public function edit(TechnicalReport $technicalReport): Response
    {
        return Inertia::render('TechnicalReports/Edit', [
            'technicalReport' => $this->reportData($technicalReport->load('protectedArea:id,name')),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, TechnicalReport $technicalReport): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->rules($technicalReport->report_type),
            'remove_attachment' => ['nullable', 'boolean'],
        ]);
        if (! $request->hasFile('attachment') && ($request->boolean('remove_attachment') || ! app(ComplianceMovService::class)->hasValidSingleFile($technicalReport, 'attachment'))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['attachment' => ComplianceMovService::MESSAGE]);
        }
        $oldPath = $technicalReport->attachment;
        $storedPath = null;
        $shouldRemoveOld = $request->boolean('remove_attachment') || $request->hasFile('attachment');

        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $storedPath = $file->store('technical-reports', 'public');
                if (! is_string($storedPath)) {
                    throw new RuntimeException('The attachment could not be stored.');
                }
                $validated['attachment'] = $storedPath;
                $validated['attachment_original_name'] = $file->getClientOriginalName();
                $validated['attachment_mime_type'] = $file->getMimeType() ?: $file->getClientMimeType();
                $validated['attachment_size'] = $file->getSize();
            } elseif ($request->boolean('remove_attachment')) {
                $validated['attachment'] = null;
                $validated['attachment_original_name'] = null;
                $validated['attachment_mime_type'] = null;
                $validated['attachment_size'] = null;
            }

            DB::transaction(fn () => $technicalReport->update([
                ...$this->persistenceData($validated),
                'updated_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }
            throw $exception;
        }

        if ($shouldRemoveOld && $oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return to_route('technical-reports.index')->with('success', 'General report updated successfully.');
    }

    public function viewAttachment(TechnicalReport $technicalReport): BinaryFileResponse
    {
        abort_unless($technicalReport->attachment && Storage::disk('public')->exists($technicalReport->attachment), 404);

        return response()->file(Storage::disk('public')->path($technicalReport->attachment));
    }

    public function destroy(TechnicalReport $technicalReport): RedirectResponse
    {
        $path = $technicalReport->attachment;
        DB::transaction(fn () => $technicalReport->delete());

        if ($path) {
            Storage::disk('public')->delete($path);
        }

        return to_route('technical-reports.index')->with('success', 'General report deleted successfully.');
    }

    private function rules(?string $legacyDocumentType = null, bool $requireMov = false): array
    {
        $documentTypes = array_values(array_unique(array_filter(['Final Report', 'Progress Report', $legacyDocumentType])));

        return [
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'target_office' => ['nullable', 'string', 'max:255'],
            'activity_name' => ['nullable', 'string', 'max:255'],
            'report_type' => ['required', 'string', Rule::in($documentTypes)],
            'semester' => ['required', Rule::in(['1st Semester', '2nd Semester'])],
            'date_conducted' => ['nullable', 'string', 'max:255'],
            'date_accomplished' => ['nullable', 'date'],
            'date_report_released_cenro' => ['nullable', 'date'],
            'date_received_penro' => ['nullable', 'date'],
            'date_endorsed_regional' => ['nullable', 'date'],
            'attachment' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:20480'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function persistenceData(array $validated): array
    {
        $received = $validated['date_received_penro'] ?? null;

        return [
            ...collect($validated)->except(['date_received_penro', 'remarks', 'remove_attachment'])->all(),
            'submission_date' => $received,
            'status' => $received ? 'Submitted' : 'Pending',
            'recommendations' => $validated['remarks'] ?? null,
        ];
    }

    private function reportData(TechnicalReport $report): array
    {
        return [
            'id' => $report->id,
            'protected_area_id' => $report->protected_area_id,
            'protected_area_name' => $report->protectedArea?->name ?? 'Unknown',
            'target_office' => $report->target_office,
            'activity_name' => $report->activity_name,
            'report_type' => $report->report_type,
            'semester' => $report->semester,
            'date_conducted' => $report->date_conducted,
            'date_accomplished' => $report->date_accomplished?->toDateString(),
            'date_report_released_cenro' => $report->date_report_released_cenro?->toDateString(),
            'date_received_penro' => $report->submission_date?->toDateString(),
            'date_endorsed_regional' => $report->date_endorsed_regional?->toDateString(),
            'remarks' => $report->recommendations,
            'deadline_submission' => $report->deadline_submission,
            'number_days_complied' => $report->number_days_complied,
            'timeliness' => $report->timeliness,
            'submission_status' => $report->submission_status,
            'total_days_delayed_penro' => $report->total_days_delayed_penro,
            'attachment' => $report->attachment ? [
                'name' => $report->attachment_original_name ?: basename($report->attachment),
                'type' => $report->attachment_mime_type ?: '',
                'size' => $report->attachment_size,
                'url' => route('technical-reports.attachment.show', $report),
            ] : null,
        ];
    }

    private function formOptions(): array
    {
        return [
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
            'reportTypes' => ['Final Report', 'Progress Report'],
        ];
    }
}
