<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded for the initial page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props shared with every Inertia page.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'canManageUsers' => fn (): bool => $request->user()?->hasRole('CDS Admin') ?? false,
                'canViewProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.view') ?? false,
                'canCreateProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.create') ?? false,
                'canUpdateProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.update') ?? false,
                'canDeleteProtectedAreas' => fn (): bool => $request->user()?->can('protected-areas.delete') ?? false,
                'canViewManagementPlans' => fn (): bool => $request->user()?->can('management-plans.view') ?? false,
                'canCreateManagementPlans' => fn (): bool => $request->user()?->can('management-plans.create') ?? false,
                'canUpdateManagementPlans' => fn (): bool => $request->user()?->can('management-plans.update') ?? false,
                'canDeleteManagementPlans' => fn (): bool => $request->user()?->can('management-plans.delete') ?? false,
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
