<?php

namespace App\Http\Controllers;

use App\Models\BmsThreat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BmsThreatController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        BmsThreat::create($validated);

        return redirect()->back()->with('success', 'Threat record successfully saved.');
    }

    public function update(Request $request, BmsThreat $bmsThreat)
    {
        $validated = $request->validate($this->validationRules());

        $bmsThreat->update($validated);

        return redirect()->back()->with('success', 'Threat record successfully updated.');
    }

    public function destroy(BmsThreat $bmsThreat)
    {
        $bmsThreat->delete();

        return redirect()->back()->with('success', 'Threat record successfully deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:bms_threats,id'],
        ]);

        DB::transaction(function () use ($validated) {
            BmsThreat::whereIn('id', $validated['ids'])->delete();
        });

        return redirect()->back()->with('success', 'Selected threat records successfully deleted.');
    }

    private function validationRules(): array
    {
        return [
            'protected_area_id' => ['nullable', 'exists:protected_areas,id'],
            'date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'threat_type' => ['required', 'string', 'max:255'],
            'threat_detail' => ['nullable', 'string', 'max:255'],
            'extent' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:255'],
            'coord_format' => ['nullable', 'string', 'in:DD,DMS,UTM'],
            'latitude' => ['nullable', 'string', 'max:50'],
            'longitude' => ['nullable', 'string', 'max:50'],
            'lat_deg' => ['nullable', 'string', 'max:20'],
            'lat_min' => ['nullable', 'string', 'max:20'],
            'lat_sec' => ['nullable', 'string', 'max:20'],
            'long_deg' => ['nullable', 'string', 'max:20'],
            'long_min' => ['nullable', 'string', 'max:20'],
            'long_sec' => ['nullable', 'string', 'max:20'],
            'utm_zone' => ['nullable', 'string', 'max:20'],
            'easting' => ['nullable', 'string', 'max:50'],
            'northing' => ['nullable', 'string', 'max:50'],
            'actions_taken' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
