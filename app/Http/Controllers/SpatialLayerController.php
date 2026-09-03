<?php

namespace App\Http\Controllers;

use App\Models\SpatialLayer;
use App\Services\Authorization\OrganizationalAccessService;

class SpatialLayerController extends Controller
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function destroy(SpatialLayer $spatialLayer)
    {
        $expectedSource = request()->routeIs('bms.spatial-layers.destroy') ? 'bms' : 'bams';
        abort_unless($this->organization->isGlobal(request()->user()) || $spatialLayer->source_key === $expectedSource, 403);
        $this->organization->assertCanAccessProtectedArea(request()->user(), $spatialLayer->protected_area_id);
        $spatialLayer->delete();

        return redirect()->back()->with('success', 'Spatial layer removed successfully.');
    }
}
