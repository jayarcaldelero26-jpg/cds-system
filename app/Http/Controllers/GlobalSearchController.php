<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use App\Models\ProgramProjectActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('q');

        if (empty($query)) {
            return response()->json([]);
        }

        // 1. Pagpangita sa Protected Areas
        $protectedAreas = ProtectedArea::where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'title' => $item->name,
                'category' => 'Protected Area',
                'url' => '/protected-areas', // Pwede ra pod nimo i-direct sa edit/view
            ]);

        // 2. Pagpangita sa Management Plans
        $plans = ManagementPlan::where('title', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'title' => $item->title,
                'category' => 'Management Plan',
                'url' => '/management-plans',
            ]);

        // 3. Pagpangita sa PPAs
        $ppas = ProgramProjectActivity::where('title', 'like', "%{$query}%")
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'title' => $item->title,
                'category' => 'PPA Project',
                'url' => '/program-project-activities',
            ]);

        // Isagol ang tanang resulta
        $results = collect()
            ->merge($protectedAreas)
            ->merge($plans)
            ->merge($ppas);

        return response()->json($results);
    }
}
