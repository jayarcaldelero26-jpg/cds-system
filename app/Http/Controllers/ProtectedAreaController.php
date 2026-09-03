<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProtectedAreaRequest;
use App\Http\Requests\UpdateProtectedAreaRequest;
use App\Models\ProtectedArea;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProtectedAreaController extends Controller
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';
        $sortable = ['name', 'category', 'municipality', 'pamo', 'pasu', 'status'];

        if (! in_array($sort, $sortable, true)) {
            $sort = 'name';
            $direction = 'asc';
        }

        return Inertia::render('ProtectedAreas/Index', [
            'protectedAreas' => $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%");
                }))
                ->orderBy($sort, $direction)
                ->paginate(15)
                ->withQueryString()
                ->through(fn (ProtectedArea $protectedArea): array => $this->protectedAreaData($protectedArea)),
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ProtectedAreas/Create', ['officeOptions' => $this->organization->officeOptions()]);
    }

    public function store(StoreProtectedAreaRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $officeId = (int) $data['supervising_office_id'];
        unset($data['supervising_office_id']);
        $area = ProtectedArea::create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->organization->assignSupervisingOffice($area, $officeId, $request->user());

        return to_route('protected-areas.index')->with('success', 'Protected area created successfully.');
    }

    public function edit(Request $request, ProtectedArea $protectedArea): Response
    {
        $this->organization->assertCanAccessProtectedArea($request->user(), $protectedArea->id);
        return Inertia::render('ProtectedAreas/Edit', ['protectedArea' => $this->protectedAreaData($protectedArea), 'officeOptions' => $this->organization->officeOptions()]);
    }

    public function update(UpdateProtectedAreaRequest $request, ProtectedArea $protectedArea): RedirectResponse
    {
        $this->organization->assertCanAccessProtectedArea($request->user(), $protectedArea->id);
        $data = $request->validated();
        $officeId = (int) $data['supervising_office_id'];
        unset($data['supervising_office_id']);
        $protectedArea->update([...$data, 'updated_by' => $request->user()->id]);
        $this->organization->assignSupervisingOffice($protectedArea, $officeId, $request->user());

        return to_route('protected-areas.index')->with('success', 'Protected area updated successfully.');
    }

    public function destroy(Request $request, ProtectedArea $protectedArea): RedirectResponse
    {
        $this->organization->assertCanAccessProtectedArea($request->user(), $protectedArea->id);
        $protectedArea->update(['updated_by' => $request->user()->id]);
        $protectedArea->delete();

        return to_route('protected-areas.index')->with('success', 'Protected area deleted successfully.');
    }

    /** @return array<string, mixed> */
    private function protectedAreaData(ProtectedArea $protectedArea): array
    {
        $protectedArea->loadMissing('supervisingOffice');
        return [
            'id' => $protectedArea->id,
            'name' => $protectedArea->name,
            'short_name' => $protectedArea->short_name,
            'category' => $protectedArea->category,
            'municipality' => $protectedArea->municipality,
            'province' => $protectedArea->province,
            'region' => $protectedArea->region,
            'area_hectares' => $protectedArea->area_hectares,
            'core_zone_hectares' => $protectedArea->core_zone_hectares,
            'buffer_zone_hectares' => $protectedArea->buffer_zone_hectares,
            'pamo' => $protectedArea->pamo,
            'pasu' => $protectedArea->pasu,
            'year_established' => $protectedArea->year_established,
            'legal_basis' => $protectedArea->legal_basis,
            'description' => $protectedArea->description,
            'status' => $protectedArea->status,
            'remarks' => $protectedArea->remarks,
            'supervising_office_id' => $protectedArea->supervisingOffice?->id,
            'supervising_office_name' => $protectedArea->supervisingOffice?->name,
        ];
    }
}
