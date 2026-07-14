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
            ],
            'status' => fn (): ?string => $request->session()->get('status'),
        ];
    }
}
