<?php

namespace App\Http\Controllers;

use App\Models\SpatialLayer;

class SpatialLayerController extends Controller
{
    public function destroy(SpatialLayer $spatialLayer)
    {
        $spatialLayer->delete();

        return redirect()->back()->with('success', 'Spatial layer removed successfully.');
    }
}
