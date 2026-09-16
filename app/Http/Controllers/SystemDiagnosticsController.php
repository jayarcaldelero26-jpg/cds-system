<?php

namespace App\Http\Controllers;

use App\Services\Diagnostics\SystemDiagnosticsService;
use Inertia\Inertia;
use Inertia\Response;

final class SystemDiagnosticsController extends Controller
{
    public function index(SystemDiagnosticsService $diagnostics): Response
    {
        return Inertia::render('Admin/Settings/SystemDiagnostics', ['diagnostics' => $diagnostics->run()]);
    }
}
