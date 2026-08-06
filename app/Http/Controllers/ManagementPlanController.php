<?php

namespace App\Http\Controllers;

use App\Models\ManagementPlan;
use App\Models\ProtectedArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ManagementPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $filters = [
            'search' => $search,
            'protected_area_id' => $request->integer('protected_area_id') ?: null,
            'plan_type' => $request->string('plan_type')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        return Inertia::render('ManagementPlans/Index', [
            'managementPlans' => ManagementPlan::query()
                ->with('protectedArea:id,name')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('plan_type', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('prepared_year', 'like', "%{$search}%")
                        ->orWhereHas('protectedArea', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                }))
                ->when($filters['protected_area_id'], fn ($query, $id) => $query->where('protected_area_id', $id))
                ->when($filters['plan_type'], fn ($query, $type) => $query->where('plan_type', $type))
                ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
                ->latest('prepared_year')
                ->paginate(15)
                ->withQueryString()
                ->through(fn (ManagementPlan $plan) => $this->planData($plan)),
            'filters' => $filters,
            'protectedAreas' => $this->protectedAreaOptions(),
            'planTypes' => $this->planTypes(),
            'statuses' => $this->statuses(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ManagementPlans/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'plan_type' => ['required', 'string', 'in:PAMP,EMP,CEPA,ECC,CNC,Other'],
            'title' => ['required', 'string', 'max:255'],
            'prepared_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'approval_date' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', 'string', 'in:Active,For Update,Under Review'],
            'remarks' => ['nullable', 'string'],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'],
        ]);

        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('management-plans', 'public');
                $attachmentPaths[] = $path;
            }
        }

        ManagementPlan::create([
            ...collect($data)->except('attachments')->toArray(),
            'attachments' => $attachmentPaths,
            'version' => 'v1',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return to_route('management-plans.index')->with('status', 'management-plan-created');
    }

    public function edit(ManagementPlan $managementPlan): Response
    {
        return Inertia::render('ManagementPlans/Edit', [
            'managementPlan' => $this->planData($managementPlan->load('protectedArea:id,name')),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, ManagementPlan $managementPlan): RedirectResponse
    {
        $data = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'plan_type' => ['required', 'string', 'in:PAMP,EMP,CEPA,ECC,CNC,Other'],
            'title' => ['required', 'string', 'max:255'],
            'prepared_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'approval_date' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', 'string', 'in:Active,For Update,Under Review'],
            'remarks' => ['nullable', 'string'],
            'attachments.*' => ['nullable', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'],
            'removed_attachments' => ['nullable', 'array'],
        ]);

        $currentAttachments = $managementPlan->attachments ?? [];
        if (is_string($currentAttachments)) {
            $currentAttachments = json_decode($currentAttachments, true) ?? [];
        }

        // Tangtangon ang mga gipili nga tanggalon nga files
        if ($request->has('removed_attachments')) {
            $removed = $request->input('removed_attachments', []);
            $currentAttachments = array_values(array_filter($currentAttachments, function ($file) use ($removed) {
                return !in_array($file, $removed);
            }));
            foreach ($removed as $remFile) {
                Storage::disk('public')->delete($remFile);
            }
        }

        // I-add ang mga bag-ong gi-upload nga files
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('management-plans', 'public');
                $currentAttachments[] = $path;
            }
        }

        $managementPlan->update([
            ...collect($data)->except(['attachments', 'removed_attachments'])->toArray(),
            'attachments' => $currentAttachments,
            'version' => $managementPlan->version ?? 'v1',
            'updated_by' => $request->user()->id,
        ]);

        return to_route('management-plans.index')->with('status', 'management-plan-updated');
    }

    public function destroy(Request $request, ManagementPlan $managementPlan): RedirectResponse
    {
        $managementPlan->update(['updated_by' => $request->user()->id]);
        $managementPlan->delete();

        return to_route('management-plans.index')->with('status', 'management-plan-deleted');
    }

    public function summary(Request $request): Response
    {
        $protectedAreaId = $request->integer('protected_area_id');
        $selectedArea = $protectedAreaId ? ProtectedArea::find($protectedAreaId) : null;

        $plans = ManagementPlan::query()
            ->when($protectedAreaId, fn ($query) => $query->where('protected_area_id', $protectedAreaId))
            ->get();

        return Inertia::render('ManagementPlans/Summary', [
            'protectedAreas' => $this->protectedAreaOptions(),
            'selectedArea' => $selectedArea,
            'summaryData' => [
                'total_plans' => $plans->count(),
                'by_type' => $plans->groupBy('plan_type')->map->count(),
                'by_status' => $plans->groupBy('status')->map->count(),
                'plans' => $plans->map(fn ($plan) => $this->planData($plan)),
            ],
            'filters' => ['protected_area_id' => $protectedAreaId],
        ]);
    }

    /** @return array<string, mixed> */
    private function planData(ManagementPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'protected_area_id' => $plan->protected_area_id,
            'protected_area_name' => $plan->protectedArea?->name,
            'plan_type' => $plan->plan_type,
            'title' => $plan->title,
            'version' => $plan->version,
            'prepared_year' => $plan->prepared_year,
            'approval_date' => $plan->approval_date?->toDateString(),
            'valid_from' => $plan->valid_from?->toDateString(),
            'valid_until' => $plan->valid_until?->toDateString(),
            'status' => $plan->status,
            'remarks' => $plan->remarks,
            'attachments' => $plan->attachments,
        ];
    }

    /** @return array<int, array{id: int, name: string}> */
    private function protectedAreaOptions(): array
    {
        return ProtectedArea::query()->orderBy('name')->get(['id', 'name'])->map(fn (ProtectedArea $area) => ['id' => $area->id, 'name' => $area->name])->all();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return ['protectedAreas' => $this->protectedAreaOptions(), 'planTypes' => $this->planTypes(), 'statuses' => $this->statuses()];
    }

    /** @return array<int, string> */
    private function planTypes(): array { return ['PAMP', 'EMP', 'CEPA', 'ECC', 'CNC', 'Other']; }

    /** @return array<int, string> */
    private function statuses(): array { return ['Active', 'For Update', 'Under Review']; }
}
