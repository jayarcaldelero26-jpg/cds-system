<?php

namespace App\Http\Controllers;

use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ProtectedArea;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Compliance\ComplianceMovService;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImeaFacilityMaintenanceReportController extends Controller
{
    public function __construct(
        private readonly ProtectedAttachmentService $attachments,
        private readonly OrganizationalAccessService $organization,
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

        return Inertia::render('Imea/MaintenanceReports', ['reports' => $reports, 'protectedAreas' => $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')->orderBy('name')->get(['id', 'name']), 'filters' => $request->only(['search', 'protected_area_id', 'quarter'])]);
    }

    public function store(Request $request): RedirectResponse { return $this->persist($request, new ImeaFacilityMaintenanceReport); }
    public function update(Request $request, ImeaFacilityMaintenanceReport $maintenanceReport): RedirectResponse { $this->organization->assertCanAccessProtectedArea($request->user(), $maintenanceReport->protected_area_id); return $this->persist($request, $maintenanceReport); }
    public function destroy(ImeaFacilityMaintenanceReport $maintenanceReport): RedirectResponse { $this->organization->assertCanAccessProtectedArea(request()->user(), $maintenanceReport->protected_area_id); $path = $maintenanceReport->mov_file_path; DB::transaction(fn () => $maintenanceReport->delete()); if ($path) $this->attachments->delete($path); return back()->with('success', 'Maintenance report deleted successfully.'); }
    public function showMov(ImeaFacilityMaintenanceReport $maintenanceReport): BinaryFileResponse { $this->organization->assertCanAccessProtectedArea(request()->user(), $maintenanceReport->protected_area_id); return $this->attachments->response('imea-maintenance', $maintenanceReport, 'mov'); }

    private function persist(Request $request, ImeaFacilityMaintenanceReport $report): RedirectResponse
    {
        $wasExisting = $report->exists;
        if ($wasExisting) {
            $this->organization->assertCanAccessProtectedArea($request->user(), $report->protected_area_id);
        }
        $validated = $request->validate($this->rules(requireMov: ! $wasExisting), [
            'mov.required' => 'A report attachment / MOV is required.',
            'mov.max' => 'The MOV attachment must not exceed 20 MB.',
        ]);
        $this->organization->assertCanAccessProtectedArea($request->user(), $validated['protected_area_id']);
        if ($wasExisting && ! $request->hasFile('mov') && ! app(ComplianceMovService::class)->hasValidSingleFile($report, 'mov_file_path')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mov' => ComplianceMovService::MESSAGE]);
        }
        $oldPath = $report->mov_file_path;
        $newPath = null;
        $removeOld = $request->hasFile('mov');
        try {
            if ($request->hasFile('mov')) {
                $file = $request->file('mov');
                $newPath = $this->attachments->store($file, 'imea-maintenance');
                if (! is_string($newPath)) throw new RuntimeException('The MOV could not be stored.');
                $validated = [...$validated, 'mov_file_name' => $file->getClientOriginalName(), 'mov_file_path' => $newPath, 'mov_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'mov_size' => $file->getSize()];
            }
            unset($validated['mov']);
            $validated['updated_by'] = $request->user()->id;
            if (! $wasExisting) $validated['created_by'] = $request->user()->id;
            DB::transaction(fn () => $wasExisting ? $report->update($validated) : ImeaFacilityMaintenanceReport::create($validated));
        } catch (Throwable $exception) { if ($newPath) $this->attachments->delete($newPath); throw $exception; }
        if ($removeOld && $oldPath) $this->attachments->delete($oldPath);
        return back()->with('success', $wasExisting ? 'Maintenance report updated successfully.' : 'Maintenance report added successfully.');
    }

    private function rules(bool $requireMov = false): array
    {
        return ['protected_area_id' => ['required', 'exists:protected_areas,id'], 'target_office' => ['required', 'string', 'max:255'], 'activity_name' => ['required', 'string', 'max:255'], 'document_type' => ['required', 'in:Final Report,Progress Report'], 'quarter' => ['required', 'in:Quarter 1,Quarter 2,Quarter 3,Quarter 4'], 'date_conducted' => ['nullable', 'string', 'max:255'], 'date_accomplished' => ['nullable', 'date'], 'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'], 'remarks' => ['nullable', 'string']];
    }
    private function data(ImeaFacilityMaintenanceReport $report): array { return [...collect($report->toArray())->except(['mov_file_path', 'mov_file_name', 'mov_mime_type', 'mov_size'])->all(), 'protected_area_name' => $report->protectedArea?->name, 'mov' => $report->mov_file_path ? $this->attachments->descriptor('imea-maintenance', $report, 'mov') : null]; }
}
