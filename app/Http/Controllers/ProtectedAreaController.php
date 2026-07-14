<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProtectedAreaRequest;
use App\Http\Requests\UpdateProtectedAreaRequest;
use App\Models\ProtectedArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProtectedAreaController extends Controller
{
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
            'protectedAreas' => ProtectedArea::query()
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
        return Inertia::render('ProtectedAreas/Create');
    }

    public function store(StoreProtectedAreaRequest $request): RedirectResponse
    {
        ProtectedArea::create([...$request->validated(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return to_route('protected-areas.index')->with('status', 'protected-area-created');
    }

    public function edit(ProtectedArea $protectedArea): Response
    {
        return Inertia::render('ProtectedAreas/Edit', ['protectedArea' => $this->protectedAreaData($protectedArea)]);
    }

    public function update(UpdateProtectedAreaRequest $request, ProtectedArea $protectedArea): RedirectResponse
    {
        $protectedArea->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return to_route('protected-areas.index')->with('status', 'protected-area-updated');
    }

    public function destroy(Request $request, ProtectedArea $protectedArea): RedirectResponse
    {
        $protectedArea->update(['updated_by' => $request->user()->id]);
        $protectedArea->delete();

        return to_route('protected-areas.index')->with('status', 'protected-area-deleted');
    }

    /** @return array<string, mixed> */
    private function protectedAreaData(ProtectedArea $protectedArea): array
    {
        return [
            'id' => $protectedArea->id,
            'name' => $protectedArea->name,
            'short_name' => $protectedArea->short_name,
            'category' => $protectedArea->category,
            'municipality' => $protectedArea->municipality,
            'province' => $protectedArea->province,
            'region' => $protectedArea->region,
            'area_hectares' => $protectedArea->area_hectares,
            'pamo' => $protectedArea->pamo,
            'pasu' => $protectedArea->pasu,
            'year_established' => $protectedArea->year_established,
            'legal_basis' => $protectedArea->legal_basis,
            'description' => $protectedArea->description,
            'status' => $protectedArea->status,
            'remarks' => $protectedArea->remarks,
        ];
    }
}
