<?php

namespace App\Http\Controllers;

use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Storage\StorageCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StorageSettingsController extends Controller
{
    public function index(Request $request, StorageCapacityService $capacity): Response
    {
        $this->authorizeSuperAdmin($request);
        return Inertia::render('Admin/Settings/Storage', ['capacity' => $capacity->current()]);
    }

    public function refresh(Request $request, StorageCapacityService $capacity): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $capacity->refresh();
        return back()->with('success', 'Storage capacity refreshed.');
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN), 403);
    }
}
