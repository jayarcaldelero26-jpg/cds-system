<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ProtectedArea;
use App\Services\SpatialLayerService;
use App\Models\BamsFlora;
use App\Models\BamsFauna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Authorization\OrganizationalAccessService;

class BamsAssessmentController extends Controller
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function index(Request $request)
    {
        $protectedAreas = $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $request->user(), 'id')->get();
        $floraRecords = $this->organization->scopeProtectedAreaQuery(BamsFlora::with('protectedArea'), $request->user())->latest()->get();

        $selectedPaId = $request->input('protected_area_id');
        if ($selectedPaId !== null) {
            $this->organization->assertCanAccessProtectedArea($request->user(), $selectedPaId);
        }
        $activePa = $selectedPaId ? ProtectedArea::query()->whereKey($selectedPaId)->first() : $protectedAreas->first();
        $spatialLayers = $activePa?->spatialLayers()->where(fn ($query) => $query->where('source_key', 'bams')->orWhereNull('source_key'))->latest('id')->get() ?? collect();

        return Inertia::render('Bams/Index', [
            'protectedAreas' => $protectedAreas,
            'bamsRecords' => $floraRecords,
            'spatialLayers' => $spatialLayers,
            'filters' => $request->only(['search', 'vegetation_type', 'protected_area_id']),
        ]);
    }

    public function storeFlora(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'plot_no' => ['nullable', 'string', 'max:50'],
            'quadrat_no' => ['required', 'integer', 'min:1'],
            'transect_no' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date'],
            'time' => ['nullable', 'string', 'max:50'],
            'observer' => ['nullable', 'string', 'max:255'],
            'vegetation_type' => ['nullable', 'string', 'max:255'],
            'weather' => ['nullable', 'string', 'max:255'],
            'elevation' => ['nullable', 'numeric'],
            'gps_unit' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'long' => ['nullable', 'numeric', 'between:-180,180'],
            'species_code' => ['required', 'string', 'max:255'],
            'dbh' => ['required', 'numeric', 'min:0'],
            'th' => ['nullable', 'numeric', 'min:0'],
            'mh' => ['nullable', 'numeric', 'min:0'],
            'bearing' => ['nullable', 'string', 'max:50'],
            'distance' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);
        $this->organization->assertCanAccessProtectedArea($request->user(), $validated['protected_area_id']);

        BamsFlora::create($validated);

        return redirect()->back()->with('success', 'Permanent Monitoring Plot record successfully added.');
    }

    public function storeFauna(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'fauna_type' => ['required', 'in:herpetofauna,avifauna,mammal,arthropod'],
            'quadrat_no' => ['nullable', 'integer', 'min:1'],
            'transect_no' => ['nullable', 'integer', 'min:1'],
            'species' => ['required', 'string', 'max:255'],
            'status_seen_heard' => ['nullable', 'string', 'max:255'],
            'frequency' => ['nullable', 'integer', 'min:0'],
            'svl' => ['nullable', 'numeric', 'min:0'],
            't_l' => ['nullable', 'numeric', 'min:0'],
            'h_l' => ['nullable', 'numeric', 'min:0'],
            'f_l' => ['nullable', 'numeric', 'min:0'],
            'wt' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);
        $this->organization->assertCanAccessProtectedArea($request->user(), $validated['protected_area_id']);

        BamsFauna::create($validated);
        return redirect()->back()->with('success', 'Fauna assessment record successfully added.');
    }

    public function storeSpatial(Request $request)
    {
        $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'spatial_geojson' => ['required', 'string', 'max:52428800'],
            'layer_name' => ['nullable', 'string', 'max:255'],
            'source_format' => ['nullable', 'in:geojson,shapefile'],
            'original_filename' => ['nullable', 'string', 'max:255'],
        ]);
        $this->organization->assertCanAccessProtectedArea($request->user(), $request->input('protected_area_id'));

        app(SpatialLayerService::class)->create([
            'protected_area_id' => $request->integer('protected_area_id'),
            'name' => $request->input('layer_name'),
            'source_format' => $request->input('source_format', 'geojson'),
            'original_filename' => $request->input('original_filename'),
            'geojson' => $request->input('spatial_geojson'),
            'source_key' => 'bams',
        ], $request->user()->id);

        $redirectQuery = array_filter([
            'protected_area_id' => $request->integer('protected_area_id'),
            ...$request->only(['search', 'vegetation_type']),
        ], fn ($value) => $value !== null && $value !== '');

        return redirect()->route('bams.index', $redirectQuery)
            ->with('success', 'Spatial layer successfully uploaded and added to the map!');
    }

    public function calculateIndices(Request $request)
    {
        return response()->json([
            'message' => 'Biodiversity index calculation is unavailable because no validated calculation input contract is defined.',
        ], 422);
    }
}
