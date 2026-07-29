<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ProtectedArea;
use App\Models\BamsFlora;
use App\Models\BamsFauna;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log; // <-- Gidugang para sa logging

class BamsAssessmentController extends Controller
{
    public function index(Request $request)
    {
        $protectedAreas = ProtectedArea::all();
        $floraRecords = BamsFlora::with('protectedArea')->latest()->paginate(10);

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

        // I-log sa storage/logs/laravel.log kung unsay napasa sa frontend
        Log::info('INDEX MAP - Spatial Data Loaded:', ['spatialDataIsNull' => is_null($spatialData)]);

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
            'protected_area_id' => 'required|exists:protected_areas,id',
            'plot_no' => 'nullable|string|max:50',
            'quadrat_no' => 'required|string|max:50',
            'tree_no' => 'nullable|string|max:50',
            'species_code' => 'required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'family_name' => 'nullable|string|max:255',
            'dbh' => 'required|numeric',
            'th' => 'nullable|numeric',
            'mh' => 'nullable|numeric',
            'bearing' => 'nullable|string|max:50',
            'distance' => 'nullable|numeric',
            'remarks' => 'nullable|string',
        ]);

        BamsFlora::create($validated);
        return redirect()->back()->with('success', 'Permanent Monitoring Plot record successfully added.');
    }

    public function storeFauna(Request $request)
    {
        $validated = $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'fauna_type' => 'required|in:herpetofauna,avifauna,mammal,arthropod',
            'species' => 'required|string|max:255',
            'status_seen_heard' => 'required|string',
            'frequency' => 'nullable|integer',
            'measurements' => 'nullable|array',
            'remarks' => 'nullable|string',
        ]);

        BamsFauna::create($validated);
        return redirect()->back()->with('success', 'Fauna assessment record successfully added.');
    }

    public function storeSpatial(Request $request)
    {
        // I-log kung naabot ba ang request sa storeSpatial
        Log::info('STORE SPATIAL CALLED', [
            'all_request' => $request->all(),
            'has_file' => $request->hasFile('spatial_file')
        ]);

        $request->validate([
            'protected_area_id' => 'required|exists:protected_areas,id',
            'spatial_file' => 'required|file|mimes:json,geojson,txt|max:10240',
        ]);

        $file = $request->file('spatial_file');
        $content = file_get_contents($file->getRealPath());
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('STORE SPATIAL ERROR: Invalid JSON format');
            return redirect()->back()->withErrors(['spatial_file' => 'Ang gi-upload nga file dili balido nga JSON format.']);
        }

        $features = [];
        if (isset($decoded['features'])) {
            foreach ($decoded['features'] as $feat) {
                if (isset($feat['geometry']['rings'])) {
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Polygon',
                            'coordinates' => $feat['geometry']['rings']
                        ],
                        'properties' => $feat['attributes'] ?? []
                    ];
                } else {
                    $features[] = $feat;
                }
            }
        }

        $standardGeoJson = [
            'type' => 'FeatureCollection',
            'features' => !empty($features) ? $features : ($decoded['features'] ?? [])
        ];

        $protectedArea = ProtectedArea::findOrFail($request->protected_area_id);
        $protectedArea->spatial_data = json_encode($standardGeoJson);
        $protectedArea->save();

        Log::info('STORE SPATIAL SUCCESS: Saved to PA ID ' . $request->protected_area_id);

        return redirect()->back()->with('success', 'Spatial boundary file successfully uploaded, converted, and rendered!');
    }

    public function calculateIndices(Request $request)
    {
        return response()->json([
            'message' => 'Computation module ready.',
            'shannon_index' => 0.00,
        ]);
    }
}
