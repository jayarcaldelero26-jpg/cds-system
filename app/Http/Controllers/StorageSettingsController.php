<?php

namespace App\Http\Controllers;

use App\Services\Authorization\OrganizationalAccessService;
use App\Services\Archive\GoogleDriveArchiveException;
use App\Services\Archive\GoogleDriveDocumentArchiveGateway;
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

    public function verifyArchiveRoot(Request $request, GoogleDriveDocumentArchiveGateway $gateway): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        try {
            $gateway->validateRootFolder();
            $result = ['status' => 'verified', 'stage' => $gateway->rootValidationStage(), 'http_status' => 200, 'category' => null];
        } catch (GoogleDriveArchiveException $exception) {
            $result = [
                'status' => $exception->httpStatus === 0 ? 'unavailable' : 'failed',
                'stage' => $gateway->rootValidationStage(),
                'http_status' => $exception->httpStatus ?: null,
                'category' => $exception->oauthErrorCategory,
            ];
        } catch (\Throwable) {
            $result = ['status' => 'unavailable', 'stage' => $gateway->rootValidationStage(), 'http_status' => null, 'category' => null];
        }

        return back()->with('archive_root_verification', $result);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN), 403);
    }
}
