<?php

namespace App\Http\Controllers;

use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ProtectedArea;
use App\Services\Compliance\ComplianceMovService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImeaFacilityMaintenanceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $reports = ImeaFacilityMaintenanceReport::query()->with('protectedArea:id,name')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->where(fn ($query) => $query->where('target_office', 'like', "%{$search}%")->orWhere('activity_name', 'like', "%{$search}%")->orWhere('document_type', 'like', "%{$search}%")->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->when($request->filled('protected_area_id'), fn ($query) => $query->where('protected_area_id', $request->integer('protected_area_id')))
            ->when($request->filled('quarter'), fn ($query) => $query->where('quarter', $request->input('quarter')))
            ->latest('id')->paginate(10)->withQueryString()->through(fn ($report) => $this->data($report));

        return Inertia::render('Imea/MaintenanceReports', ['reports' => $reports, 'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']), 'filters' => $request->only(['search', 'protected_area_id', 'quarter'])]);
    }

    public function store(Request $request): RedirectResponse { return $this->persist($request, new ImeaFacilityMaintenanceReport); }
    public function update(Request $request, ImeaFacilityMaintenanceReport $maintenanceReport): RedirectResponse { return $this->persist($request, $maintenanceReport); }
    public function destroy(ImeaFacilityMaintenanceReport $maintenanceReport): RedirectResponse { $path = $maintenanceReport->mov_file_path; DB::transaction(fn () => $maintenanceReport->delete()); if ($path) Storage::disk('public')->delete($path); return back()->with('success', 'Maintenance report deleted successfully.'); }
    public function showMov(ImeaFacilityMaintenanceReport $maintenanceReport): BinaryFileResponse { abort_unless($maintenanceReport->mov_file_path && Storage::disk('public')->exists($maintenanceReport->mov_file_path), 404); return response()->file(Storage::disk('public')->path($maintenanceReport->mov_file_path)); }

    private function persist(Request $request, ImeaFacilityMaintenanceReport $report): RedirectResponse
    {
        $wasExisting = $report->exists;
        $validated = $request->validate($this->rules(requireMov: ! $wasExisting));
        if ($wasExisting && ! $request->hasFile('mov') && ($request->boolean('delete_mov') || ! app(ComplianceMovService::class)->hasValidSingleFile($report, 'mov_file_path'))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['mov' => ComplianceMovService::MESSAGE]);
        }
        $oldPath = $report->mov_file_path;
        $newPath = null;
        $removeOld = $request->boolean('delete_mov') || $request->hasFile('mov');
        try {
            if ($request->hasFile('mov')) {
                $file = $request->file('mov');
                $newPath = $file->store('imea-maintenance-report-movs', 'public');
                if (! is_string($newPath)) throw new RuntimeException('The MOV could not be stored.');
                $validated = [...$validated, 'mov_file_name' => $file->getClientOriginalName(), 'mov_file_path' => $newPath, 'mov_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'mov_size' => $file->getSize()];
            } elseif ($request->boolean('delete_mov')) $validated = [...$validated, 'mov_file_name' => null, 'mov_file_path' => null, 'mov_mime_type' => null, 'mov_size' => null];
            unset($validated['mov'], $validated['delete_mov']);
            $validated['updated_by'] = $request->user()->id;
            if (! $wasExisting) $validated['created_by'] = $request->user()->id;
            DB::transaction(fn () => $wasExisting ? $report->update($validated) : ImeaFacilityMaintenanceReport::create($validated));
        } catch (Throwable $exception) { if ($newPath) Storage::disk('public')->delete($newPath); throw $exception; }
        if ($removeOld && $oldPath) Storage::disk('public')->delete($oldPath);
        return back()->with('success', $wasExisting ? 'Maintenance report updated successfully.' : 'Maintenance report added successfully.');
    }

    private function rules(bool $requireMov = false): array
    {
        return ['protected_area_id' => ['required', 'exists:protected_areas,id'], 'target_office' => ['required', 'string', 'max:255'], 'activity_name' => ['required', 'string', 'max:255'], 'document_type' => ['required', 'in:Final Report,Progress Report'], 'quarter' => ['required', 'in:Quarter 1,Quarter 2,Quarter 3,Quarter 4'], 'date_conducted' => ['nullable', 'string', 'max:255'], 'date_accomplished' => ['nullable', 'date'], 'date_report_released_cenro' => ['nullable', 'date'], 'date_received_penro' => ['nullable', 'date'], 'date_endorsed_regional' => ['nullable', 'date'], 'mov' => [$requireMov ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'], 'delete_mov' => ['nullable', 'boolean'], 'remarks' => ['nullable', 'string']];
    }
    private function data(ImeaFacilityMaintenanceReport $report): array { return [...$report->toArray(), 'protected_area_name' => $report->protectedArea?->name, 'mov' => $report->mov_file_path ? ['name' => $report->mov_file_name ?: basename($report->mov_file_path), 'type' => $report->mov_mime_type ?: '', 'size' => $report->mov_size, 'url' => route('imea.maintenance-reports.mov', $report)] : null]; }
}
