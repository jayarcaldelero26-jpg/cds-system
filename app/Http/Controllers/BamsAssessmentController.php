<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ProtectedArea;
use App\Models\BamsFlora;
use App\Models\BamsFauna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BamsAssessmentController extends Controller
{
    public function index(Request $request)
    {
        $protectedAreas = ProtectedArea::all();
        $floraRecords = BamsFlora::with('protectedArea')->latest()->get();

        $selectedPaId = $request->input('protected_area_id');
        $spatialData = null;
        $hasSpatialColumn = Schema::hasColumn('protected_areas', 'spatial_data');

        if ($hasSpatialColumn) {
            if ($selectedPaId) {
                $activePa = ProtectedArea::find($selectedPaId);
                $spatialData = ($activePa && $activePa->spatial_data) ? json_decode($activePa->spatial_data, true) : null;
            }

            if (!$spatialData) {
                $latestPa = ProtectedArea::whereNotNull('spatial_data')->latest('updated_at')->first();
                $spatialData = ($latestPa && $latestPa->spatial_data) ? json_decode($latestPa->spatial_data, true) : null;
            }
        }

        return Inertia::render('Bams/Index', [
            'protectedAreas' => $protectedAreas,
            'bamsRecords' => $floraRecords,
            'spatialData' => $spatialData,
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

        BamsFauna::create($validated);
        return redirect()->back()->with('success', 'Fauna assessment record successfully added.');
    }

    public function storeSpatial(Request $request)
    {
        $request->validate([
            'protected_area_id' => ['required', 'exists:protected_areas,id'],
            'spatial_file' => ['required', 'file', 'mimes:json,geojson,txt', 'max:10240'],
        ]);

        $file = $request->file('spatial_file');
        $content = file_get_contents($file->getRealPath());
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return redirect()->back()->withErrors(['spatial_file' => 'The uploaded file is not valid JSON.']);
        }

        $features = [];
        if (($decoded['type'] ?? null) !== 'FeatureCollection' || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
            return redirect()->back()->withErrors(['spatial_file' => 'The uploaded file must be a GeoJSON FeatureCollection.']);
        }

        foreach ($decoded['features'] as $feature) {
            if (! is_array($feature)) {
                return redirect()->back()->withErrors(['spatial_file' => 'Every GeoJSON feature must be an object.']);
            }

            if (isset($feature['geometry']['rings']) && is_array($feature['geometry']['rings'])) {
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Polygon',
                            'coordinates' => $feature['geometry']['rings'],
                        ],
                        'properties' => $feature['attributes'] ?? [],
                    ];
                    continue;
            }

            $geometry = $feature['geometry'] ?? null;
            $allowedGeometryTypes = ['Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon'];

            if (! is_array($geometry)
                || ! in_array($geometry['type'] ?? null, $allowedGeometryTypes, true)
                || ! isset($geometry['coordinates'])
                || ! is_array($geometry['coordinates'])
                || $geometry['coordinates'] === []) {
                return redirect()->back()->withErrors(['spatial_file' => 'Every feature must contain a supported, non-empty GeoJSON geometry.']);
            }

            $features[] = $feature;
        }

        $standardGeoJson = [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];

        $protectedArea = ProtectedArea::findOrFail($request->protected_area_id);
        DB::transaction(function () use ($protectedArea, $standardGeoJson): void {
            $protectedArea->spatial_data = json_encode($standardGeoJson, JSON_THROW_ON_ERROR);
            $protectedArea->save();
        });

        return redirect()->back()->with('success', 'Spatial boundary file successfully uploaded, converted, and rendered!');
    }

    public function calculateIndices(Request $request)
    {
        return response()->json([
            'message' => 'Biodiversity index calculation is unavailable because no validated calculation input contract is defined.',
        ], 422);
    }
}
