<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\RoutingPositionSettingsService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        $organization = app(OrganizationalAccessService::class);
        $routingSettings = app(RoutingPositionSettingsService::class);

        return Inertia::render('Auth/Register', [
            'registrationOptions' => [
                'accountRole' => OrganizationalAccessService::ACCOUNT_ROLE_USER,
                'operationalGroups' => $organization->operationalGroups($routingSettings->disabledAccountCategories()),
                'offices' => array_values(array_filter($organization->officeOptions(), fn (array $office): bool => ($office['office_type'] ?? null) === 'cenro')),
                'protectedAreas' => $organization->pamoRegistrationProtectedAreas(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = app(OrganizationalAccessService::class);
        if ($organization->normalizeCategory($request->input('section')) === OrganizationalAccessService::PAMO || $request->input('operational_group') === OrganizationalAccessService::OPERATIONAL_GROUP_PAMO) {
            $organization->assertSubmittedPamoOfficeMatches($request->input('office_designated'), $request->input('protected_area_id'));
        }
        $request->merge($organization->normalizeAssignment($request->all()));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'operational_group' => ['required', 'string', 'in:cenro,penro,pamo'],
            'office_designated' => ['nullable', 'string', 'max:255'],
            'unit_assignment' => ['nullable', 'string', 'in:conservation,development'],
            'section' => ['required', 'string', 'in:CENRO_RECORDS,PENRO_RECORDS,OFFICE_OF_THE_PENRO,PENRO_TSD_CHIEF,CENRO_CDS_CHIEF,CENRO_CDS_FOCAL,PENRO_CDS_CHIEF,PENRO_CDS_FOCAL,PAMO'],
            'protected_area_id' => [$request->input('operational_group') === 'pamo' ? 'required' : 'nullable', 'integer', 'exists:protected_areas,id'],
            'pamo_office_id' => ['prohibited'],
            'supervising_office_id' => ['prohibited'],
        ]);

        $data = $organization->normalizeAssignment($data);
        $organization->validateAssignment(
            $data['unit_assignment'] ?? null,
            $data['section'],
            $data['office_designated'] ?? null,
            $data['protected_area_id'] ?? null,
            null,
            $data['operational_group'] ?? null,
        );
        if (! app(RoutingPositionSettingsService::class)->categoryAvailableForAccount($data['section'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'section' => 'This category is unavailable while its routing position is disabled.',
            ]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'office_designated' => $data['office_designated'] ?? null,
            'section' => $data['section'],
            'unit_assignment' => $data['unit_assignment'] ?? null,
            'protected_area_id' => $data['protected_area_id'] ?? null,
            'is_approved' => false,
            'is_active' => false,
        ]);

        $user->assignRole('no_role');

        event(new Registered($user));

        return to_route('login')->with('registration_success', 'Your account has been created successfully and is awaiting administrator approval. You may sign in once your account has been activated.');
    }
}
