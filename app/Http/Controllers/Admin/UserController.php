<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Models\ProtectedArea;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;
use App\Services\AuditLogService;
use App\Services\Authorization\OrganizationalAccessService;

class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display all user accounts.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->with(['roles', 'protectedArea:id,name'])
                ->latest()
                ->paginate(15)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'office_designated' => $user->office_designated, // 🚀 Gidugang para makita sa listahan
                    'section' => $user->section,                     // 🚀 Gidugang para makita sa listahan (CDS o MES)
                    'unit_assignment' => $user->unit_assignment,
                    'effective_category' => app(OrganizationalAccessService::class)->effectiveCategory($user),
                    'protected_area_id' => app(OrganizationalAccessService::class)->effectiveCategory($user) === OrganizationalAccessService::PAMO ? $user->protected_area_id : null,
                    'protected_area_name' => $user->protectedArea?->name,
                    'access_configured' => $user->roles->contains(fn ($role): bool => $role->name !== 'no_role'),
                    'is_active' => (bool) $user->is_active,
                    'created_at' => $user->created_at?->toDateString(),
                    'updated_at' => $user->updated_at?->toDateString(),
                    'can_delete' => request()->user()?->can('delete', $user) ?? false,
                ]),
        ]);
    }

    /**
     * Display the user creation form.
     */
    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Admin/Users/Create', [
            'categories' => app(OrganizationalAccessService::class)->categoryOptions(OrganizationalAccessService::CONSERVATION, true),
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
            'offices' => app(OrganizationalAccessService::class)->canonicalOffices(),
        ]);
    }

    /**
     * Store a new user account and its role.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $role = app(OrganizationalAccessService::class)->roleForCategory($data['section']);

        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = $data['is_active'] ?? false;

        $user = User::create($data);
        if ($role) {
            $user->syncRoles([$role]);
        }
        app(AuditLogService::class)->record('user_management', 'User Created', User::class, $user->id, 'User Management', 'Created a user account.', ['category' => $user->section]);

        return to_route('admin.users.index')->with('success', 'User created successfully.');
    }

    /**
     * Display the user edit form.
     */
    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'office_designated' => $user->office_designated, // 🚀 Gidugang para ma-load sa edit form
                'section' => $user->section,                     // 🚀 Gidugang para ma-load sa edit form
                'protected_area_id' => app(OrganizationalAccessService::class)->effectiveCategory($user) === OrganizationalAccessService::PAMO ? $user->protected_area_id : null,
                'unit_assignment' => $user->unit_assignment,
                'effective_category' => app(OrganizationalAccessService::class)->effectiveCategory($user),
                'is_active' => (bool) $user->is_active,
            ],
            'categories' => app(OrganizationalAccessService::class)->categoryOptions(OrganizationalAccessService::CONSERVATION, true),
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name']),
            'offices' => app(OrganizationalAccessService::class)->canonicalOffices(),
        ]);
    }

    /**
     * Update a user account, status (approval), and role.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $oldRole = $user->roles()->first()?->name;
        $before = $user->only(['name', 'email', 'office_designated', 'section', 'unit_assignment', 'protected_area_id', 'is_active']);
        $role = app(OrganizationalAccessService::class)->roleForCategory($data['section']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (! $user->is_active && ($data['is_active'] ?? false)) {
            $activationError = $this->activationError($user, $data);
            if ($activationError) {
                throw ValidationException::withMessages(['is_active' => $activationError]);
            }
        }

        DB::transaction(function () use ($user, $data, $role): void {
            $user->update($data);
            $user->syncRoles([$role]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(AuditLogService::class)->record('user_management', 'User Updated', User::class, $user->id, 'User Management', 'Updated a user account.', ['before' => $before, 'after' => $user->fresh()->only(array_keys($before)), 'old_role' => $oldRole, 'category' => $user->section]);

        return to_route('admin.users.index')->with('success', 'User updated successfully.');
    }

    /** Activate a configured account using its already-saved access role. */
    public function activate(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $activationError = $this->activationError($user);
        if ($activationError) {
            return back()->with('error', $activationError);
        }

        if ($user->is_active) {
            return back()->with('error', 'This account is already active.');
        }

        $user->update(['is_active' => true]);
        app(AuditLogService::class)->record(
            'user_management',
            'User Activated',
            User::class,
            $user->id,
            'User Management',
            'Activated a configured user account.',
            [
                'role' => $user->roles()->first()?->name,
                'unit_assignment' => $user->unit_assignment,
                'office_designated' => $user->office_designated,
                'protected_area_id' => $user->protected_area_id,
            ],
        );

        return to_route('admin.users.index')->with('success', 'User account activated successfully.');
    }

    /**
     * Delete a user account when permitted by the policy.
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        app(AuditLogService::class)->record('user_management', 'User Deleted', User::class, $user->id, 'User Management', 'Deleted a user account.');
        $user->delete();

        return to_route('admin.users.index')->with('success', 'User deleted successfully.');
    }

    private function activationError(User $user, array $data = []): ?string
    {
        $organization = app(OrganizationalAccessService::class);
        $roleName = isset($data['section'])
            ? $organization->roleForCategory($data['section'])
            : $user->roles()->where('name', '!=', 'no_role')->first()?->name;

        if (blank($roleName) || $roleName === 'no_role') {
            return 'Please complete the user\'s access role and organizational assignment before activating this account.';
        }

        if (in_array($roleName, ['CDS Admin', 'Super Admin'], true)) {
            return null;
        }

        $unit = strtolower(trim((string) ($data['unit_assignment'] ?? $user->unit_assignment)));
        $category = (string) ($data['section'] ?? $user->section);
        $office = $data['office_designated'] ?? $user->office_designated;
        $protectedAreaId = $data['protected_area_id'] ?? $user->protected_area_id;

        if (! in_array($unit, [OrganizationalAccessService::CONSERVATION, OrganizationalAccessService::DEVELOPMENT], true)
            || ! in_array($category, $organization->categoriesForUnit($unit), true)
            || $category !== $organization->categoryForRole($roleName)) {
            return 'Please complete the user\'s access role and organizational assignment before activating this account.';
        }

        try {
            $organization->validateAssignment($unit, $category, $office, $protectedAreaId);
        } catch (ValidationException) {
            return 'Please complete the user\'s access role and organizational assignment before activating this account.';
        }

        return null;
    }
}
