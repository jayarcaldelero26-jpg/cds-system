<?php

namespace App\Http\Controllers;

use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ProtectedArea;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Compliance\ComplianceMovService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class ImeaFacilityMaintenanceReportController extends Controller
{
    public function __construct(
        private readonly ProtectedAttachmentService $attachments,
        private readonly OrganizationalAccessService $organization,
        private readonly CurrentDocumentReplacementService $documents,
        private readonly ReportDocumentAdapterResolver $documentAdapters,
    ) {}

    public function index(Request $request): Response
    {
        if ($request->filled('protected_area_id')) {
            $this->organization->assertCanAccessProtectedArea($request->user(), $request->input('protected_area_id'));
        }
        $reports = $this->organization->scopeProtectedAreaQuery(ImeaFacilityMaintenanceReport::query()->with('protectedArea:id,name'), $request->user())
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(fn ($query) => $query->where('target_office', 'like', "%{$search}%")->orWhere('activity_name', 'like', "%{$search}%")->orWhere('document_type', 'like', "%{$search}%")->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->when($request->filled('protected_area_id'), fn ($query) => $query->where('protected_area_id', $request->integer('protected_area_id')))
            ->when($request->filled('quarter'), fn ($query) => $query->where('quarter', $request->input('quarter')))
            ->latest('id')->paginate(10)->withQueryString()->through(fn ($report) => $this->data($report));

        return Inertia::render('Imea/MaintenanceReports', ['reports' => $reports, 'protectedAreas' => $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')->orderBy('name')->get(['id', 'name']), ...app(SubmissionFormScopeService::class)->options($request->user()), 'filters' => $request->only(['search', 'protected_area_id', 'quarter'])]);
    }

    public function store(Request $request): RedirectResponse { return $this->persist($request, new ImeaFacilityMaintenanceReport); }
    public function update(Request $request, ImeaFacilityMaintenanceReport $maintenanceReport): RedirectResponse { $this->organization->assertCanAccessProtectedArea($request->user(), $maintenanceReport->protected_area_id); app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($maintenanceReport); return $this->persist($request, $maintenanceReport); }
    public function destroy(ImeaFacilityMaintenanceReport $maintenanceReport): RedirectResponse { $this->organization->assertCanAccessProtectedArea(request()->user(), $maintenanceReport->protected_area_id); app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($maintenanceReport); $path = $maintenanceReport->mov_file_path; app(\App\Services\Reports\ReportTrackingReferenceLifecycle::class)->deleteSource($maintenanceReport, fn () => $maintenanceReport->delete()); if ($path) $this->attachments->delete($path); return back()->with('success', 'Maintenance report deleted successfully.'); }
    public function showMov(ImeaFacilityMaintenanceReport $maintenanceReport): HttpResponse { $this->organization->assertCanAccessProtectedArea(request()->user(), $maintenanceReport->protected_area_id); return $this->attachments->response('imea-maintenance', $maintenanceReport, 'mov'); }

    private function persist(Request $request, ImeaFacilityMaintenanceReport $report): RedirectResponse
    {
        app(SubmissionFormScopeService::class)->normalizeRequest($request);
        $wasExisting = $report->exists;
        if ($wasExisting) {
            $this->organization->assertCanAccessProtectedArea($request->user(), $report->protected_area_id);
        }
        $validated = $request->validate($this->rules(requireMov: ! $wasExisting), [
            'mov.required' => 'A report attachment / MOV is required.',
            'mov.max' => 'The MOV attachment must not exceed 20 MB.',
        ]);
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        $dates->assertNotFuture($validated['date_conducted'] ?? null, 'date_conducted', 'Date Conducted', $report->date_conducted);
        $dates->assertNotFuture($validated['date_accomplished'] ?? null, 'date_accomplished', 'Date Accomplished', $report->date_accomplished);
        $this->organization->assertCanAccessProtectedArea($request->user(), $validated['protected_area_id']);
        if ($wasExisting && ! $request->hasFile('mov') && ! app(ComplianceMovService::class)->hasValidSingleFile($report, 'mov_file_path')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mov' => ComplianceMovService::MESSAGE]);
        }
        $file = $request->file('mov');
        unset($validated['mov']);
        $validated['updated_by'] = $request->user()->id;
        if (! $wasExisting) $validated['created_by'] = $request->user()->id;
        if ($wasExisting && $file) {
            $adapter = $this->documentAdapters->resolve('imea-maintenance', $report, 'mov');
            $this->documents->replaceUsingAdapter($report, 'imea-maintenance', 'mov', $file, $adapter, 'mov', (int) $request->user()->id,
                fn (ImeaFacilityMaintenanceReport $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                $request->input('remarks'), [...$validated, 'mov_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'mov_size' => $file->getSize()], 'REPLACEMENT');
        } elseif ($wasExisting) {
            DB::transaction(fn () => $report->update($validated));
        } else {
            $report = ImeaFacilityMaintenanceReport::query()->create($validated);
            try {
                $adapter = $this->documentAdapters->resolve('imea-maintenance', $report, 'mov');
                $this->documents->replaceUsingAdapter($report, 'imea-maintenance', 'mov', $file, $adapter, 'mov', (int) $request->user()->id,
                    fn (ImeaFacilityMaintenanceReport $record) => $this->organization->assertCanAccessProtectedArea($request->user(), $record->protected_area_id),
                    $request->input('remarks'), ['mov_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'mov_size' => $file->getSize()], 'UPLOAD');
            } catch (Throwable $exception) {
                $report->delete();
                throw $exception;
            }
        }
        return back()->with('success', $wasExisting ? 'Maintenance report updated successfully.' : 'Maintenance report added successfully.');
    }

    private function rules(bool $requireMov = false): array
    {
        return ['protected_area_id' => ['required', 'exists:protected_areas,id'], 'target_office' => ['required', 'string', 'max:255'], 'activity_name' => ['required', 'string', 'max:255'], 'document_type' => ['required', 'in:Final Report,Progress Report'], 'quarter' => ['required', 'in:Quarter 1,Quarter 2,Quarter 3,Quarter 4'], 'date_conducted' => ['required', 'date'], 'date_accomplished' => ['required', 'date'], 'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'], 'remarks' => ['nullable', 'string']];
    }
    private function data(ImeaFacilityMaintenanceReport $report): array { return [...collect($report->toArray())->except(['mov_file_path', 'mov_file_name', 'mov_mime_type', 'mov_size'])->all(), 'protected_area_name' => $report->protectedArea?->name, 'mov' => $report->mov_file_path ? $this->attachments->descriptor('imea-maintenance', $report, 'mov') : null]; }
}
