<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManagementPlanRequest;
use App\Http\Requests\UpdateManagementPlanRequest;
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

    public function store(StoreManagementPlanRequest $request): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('attachment')) {
            $data['attachment'] = $request->file('attachment')->store('management-plans', 'public');
        }

        ManagementPlan::create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

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
        // 1. Diri nato i-validate direkta aron luwas sa isyu sa multipart PATCH requests
        $data = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'plan_type' => ['required', 'string', 'in:PAMP,EMP,CEPA,ECC,CNC,Other'],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:100'],
            'prepared_year' => ['required', 'integer', 'min:1900', 'max:2100'], // Gi-hardcode ngadto sa 2100 aron luwas sa server-time mismatch
            'approval_date' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', 'string', 'in:Draft,Active,Expired,For Updating,Archived'],
            'remarks' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'], // Gi-add ang image support
        ]);

        // 2. I-check ug i-save ang na-upload nga file
        if ($request->hasFile('attachment')) {
            if ($managementPlan->attachment) {
                Storage::disk('public')->delete($managementPlan->attachment);
            }
            $data['attachment'] = $request->file('attachment')->store('management-plans', 'public');
        } else {
            // Kung walay gi-upload nga bag-o, pabilin ang karaan o i-null kung gituyo og tangtang
            unset($data['attachment']);
        }

        // 3. I-save na sa database uban ang updated_by tracker
        $managementPlan->update([...$data, 'updated_by' => $request->user()->id]);

        return to_route('management-plans.index')->with('status', 'management-plan-updated');
    }

    public function destroy(Request $request, ManagementPlan $managementPlan): RedirectResponse
    {
        $managementPlan->update(['updated_by' => $request->user()->id]);
        $managementPlan->delete();

        return to_route('management-plans.index')->with('status', 'management-plan-deleted');
    }

    /** @return array<string, mixed> */
    private function planData(ManagementPlan $plan): array
    {
        return [
            'id' => $plan->id, 'protected_area_id' => $plan->protected_area_id,
            'protected_area_name' => $plan->protectedArea?->name,
            'plan_type' => $plan->plan_type, 'title' => $plan->title, 'version' => $plan->version,
            'prepared_year' => $plan->prepared_year,
            'approval_date' => $plan->approval_date?->toDateString(),
            'valid_from' => $plan->valid_from?->toDateString(),
            'valid_until' => $plan->valid_until?->toDateString(),
            'status' => $plan->status, 'remarks' => $plan->remarks, 'attachment' => $plan->attachment,
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
    private function statuses(): array { return ['Draft', 'Active', 'Expired', 'For Updating', 'Archived']; }
}
