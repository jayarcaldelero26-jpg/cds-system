<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ProtectedArea;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        $organization = app(OrganizationalAccessService::class);

        return Inertia::render('Auth/Register', [
            'registrationOptions' => [
                'units' => [
                    ['value' => OrganizationalAccessService::CONSERVATION, 'label' => 'Conservation Unit'],
                    ['value' => OrganizationalAccessService::DEVELOPMENT, 'label' => 'Development Unit'],
                ],
                'categories' => [
                    OrganizationalAccessService::CONSERVATION => $organization->categoryOptions(OrganizationalAccessService::CONSERVATION),
                    OrganizationalAccessService::DEVELOPMENT => $organization->categoryOptions(OrganizationalAccessService::DEVELOPMENT),
                ],
                'offices' => [
                    'all' => $organization->canonicalOffices(),
                    'cenro' => $organization->cenroOffices(),
                    'penro' => $organization->penroOffices(),
                ],
                'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'office_designated' => ['required', 'string', 'max:255'],
            'unit_assignment' => ['required', 'string', 'in:conservation,development'],
            // The persisted column remains section for compatibility. These
            // values describe the applicant's category; access is assigned
            // separately by an administrator after approval.
            'section' => ['required', 'string', 'in:CENRO_RECORDS,CENRO_CDS_CHIEF,CENRO_CDS_FOCAL,PENRO_CDS_CHIEF,PENRO_CDS_FOCAL,PAMO'],
            'protected_area_id' => ['nullable', 'integer', 'exists:protected_areas,id'],
        ]);

        app(OrganizationalAccessService::class)->validateAssignment(
            $request->input('unit_assignment'),
            $request->input('section'),
            $request->input('office_designated'),
            $request->input('protected_area_id'),
        );

        // Awtomatikong 'no_role' ug inactive pagka-register, lakip ang office ug section
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'office_designated' => $request->office_designated,
            'section' => $request->section,
            'unit_assignment' => $request->input('unit_assignment'),
            'protected_area_id' => $request->input('protected_area_id'),
            'is_active' => false,
        ]);

        // I-assign ang 'no_role' gamit ang Spatie roles
        $user->assignRole('no_role');

        event(new Registered($user));

        // 🚀 Gitangtang ang Auth::login($user) aron mo-pop up ang success dialog
        // ug dili mo-diretso og login samtang pending pa sa admin approval.

        return to_route('login')->with('registration_success', 'Your account has been created successfully and is awaiting administrator approval. You may sign in once your account has been activated.');
    }
}
