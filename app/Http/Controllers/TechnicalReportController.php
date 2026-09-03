<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Models\TechnicalReport;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Compliance\ComplianceMovService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TechnicalReportController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments, private readonly OrganizationalAccessService $organization) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'protected_area_id', 'target_office', 'semester', 'year']);
        if ($request->filled('protected_area_id')) $this->organization->assertCanAccessProtectedArea($request->user(), $request->input('protected_area_id'));
        $reports = $this->organization->scopeProtectedAreaQuery(TechnicalReport::query(), $request->user())
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
            ...$this->formOptions($request->user()),
            'targetOffices' => TechnicalReport::query()->whereNotNull('target_office')->distinct()->orderBy('target_office')->pluck('target_office'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('TechnicalReports/Create', $this->formOptions(request()->user()));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->rejectRoutingFields($request);
        $validated = $request->validate($this->rules(requireMov: true));
        $this->organization->assertCanAccessProtectedArea($request->user(), $validated['protected_area_id']);
        $storedPath = null;

        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $storedPath = $this->attachments->store($file, 'technical-report');
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
                $this->attachments->delete($storedPath);
            }
            throw $exception;
        }

        return to_route('technical-reports.index')->with('success', 'General report created successfully.');
    }

    public function edit(TechnicalReport $technicalReport): Response
    {
        $this->organization->assertCanAccessProtectedArea(request()->user(), $technicalReport->protected_area_id);
        return Inertia::render('TechnicalReports/Edit', [
            'technicalReport' => $this->reportData($technicalReport->load('protectedArea:id,name')),
            ...$this->formOptions(request()->user()),
        ]);
    }

    public function update(Request $request, TechnicalReport $technicalReport): RedirectResponse
    {
        $technicalReport = $this->authorizedRecord($request, $technicalReport->id);
        $this->rejectRoutingFields($request);
        $validated = $request->validate([
            ...$this->rules($technicalReport->report_type),
        ]);
        $this->organization->assertCanAccessProtectedArea($request->user(), $validated['protected_area_id'] ?? $technicalReport->protected_area_id);
        if (! $request->hasFile('attachment') && ! app(ComplianceMovService::class)->hasValidSingleFile($technicalReport, 'attachment')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['attachment' => ComplianceMovService::MESSAGE]);
        }
        $oldPath = $technicalReport->attachment;
        $storedPath = null;
        $shouldRemoveOld = $request->hasFile('attachment');

        try {
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $storedPath = $this->attachments->store($file, 'technical-report');
                if (! is_string($storedPath)) {
                    throw new RuntimeException('The attachment could not be stored.');
                }
                $validated['attachment'] = $storedPath;
                $validated['attachment_original_name'] = $file->getClientOriginalName();
                $validated['attachment_mime_type'] = $file->getMimeType() ?: $file->getClientMimeType();
                $validated['attachment_size'] = $file->getSize();
            }

            DB::transaction(fn () => $technicalReport->update([
                ...$this->persistenceData($validated, $technicalReport),
                'updated_by' => $request->user()->id,
            ]));
        } catch (Throwable $exception) {
            if ($storedPath) {
                $this->attachments->delete($storedPath);
            }
            throw $exception;
        }

        if ($shouldRemoveOld && $oldPath) {
            $this->attachments->delete($oldPath);
        }

        return to_route('technical-reports.index')->with('success', 'General report updated successfully.');
    }

    public function viewAttachment(TechnicalReport $technicalReport): BinaryFileResponse
    {
        $technicalReport = $this->authorizedRecord(request(), $technicalReport->id);
        return $this->attachments->response('technical-report', $technicalReport, 'attachment');
    }

    public function destroy(TechnicalReport $technicalReport): RedirectResponse
    {
        $technicalReport = $this->authorizedRecord(request(), $technicalReport->id);
        $path = $technicalReport->attachment;
        DB::transaction(fn () => $technicalReport->delete());

        if ($path) {
            $this->attachments->delete($path);
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
            'attachment' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx', 'max:20480'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    private function persistenceData(array $validated, ?TechnicalReport $existing = null): array
    {
        $data = [
            ...collect($validated)->except(['date_received_penro', 'remarks'])->all(),
            'recommendations' => $validated['remarks'] ?? null,
        ];

        if ($existing === null) {
            $data['submission_date'] = null;
            $data['status'] = 'Pending';
        }

        return $data;
    }

    private function rejectRoutingFields(Request $request): void
    {
        $fields = collect(['date_report_released_cenro', 'date_received_penro', 'date_endorsed_regional'])
            ->filter(fn (string $field): bool => $request->exists($field))
            ->values();

        if ($fields->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'routing' => 'Routing dates are recorded through Submission Tracking only.',
            ]);
        }
    }

    private function reportData(TechnicalReport $report): array
    {
        $data = collect($report->toArray())->except([
            'attachment',
            'attachment_original_name',
            'attachment_mime_type',
            'attachment_size',
        ])->all();

        return [
            ...$data,
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
                'key' => 'attachment',
                'name' => $report->attachment_original_name ?: basename($report->attachment),
                'mime_type' => $report->attachment_mime_type ?: '',
                'type' => $report->attachment_mime_type ?: '',
                'size' => $report->attachment_size,
                'url' => $this->attachments->url('technical-report', $report, 'attachment'),
                'external' => false,
            ] : null,
        ];
    }

    private function formOptions(?\App\Models\User $user = null): array
    {
        return [
            'protectedAreas' => $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user ?: request()->user(), 'id')->orderBy('name')->get(['id', 'name']),
            'reportTypes' => ['Final Report', 'Progress Report'],
        ];
    }

    private function authorizedRecord(Request $request, int $id): TechnicalReport
    {
        return $this->organization->scopeProtectedAreaQuery(TechnicalReport::query(), $request->user())->findOrFail($id);
    }
}
