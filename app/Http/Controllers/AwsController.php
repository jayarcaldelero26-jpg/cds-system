<?php

namespace App\Http\Controllers;

use App\Models\Aws;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AwsController extends Controller
{
    public function index()
    {
        return Inertia::render('AWS/Aws', [ // 🚀 Gi-update gikan sa 'Aws' ngadto sa 'AWS/Aws'
            'awsRecords' => Aws::latest()->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'station_name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'status' => 'required|string',
            'temperature' => 'nullable|numeric',
            'humidity' => 'nullable|numeric',
            'rainfall' => 'nullable|numeric',
        ]);

        Aws::create($validated);

        return redirect()->route('aws.index')->with('success', 'AWS record successfully added.');
    }

    public function update(Request $request, Aws $aws)
    {
        $validated = $request->validate([
            'station_name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'status' => 'required|string',
            'temperature' => 'nullable|numeric',
            'humidity' => 'nullable|numeric',
            'rainfall' => 'nullable|numeric',
        ]);

        $aws->update($validated);

        return redirect()->route('aws.index')->with('success', 'AWS record successfully updated.');
    }

    public function destroy(Aws $aws)
    {
        $aws->delete();

        return redirect()->route('aws.index')->with('success', 'AWS record successfully deleted.');
    }
}
