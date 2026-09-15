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
use Spatie\Permission\Models\Role;
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
        $organization = app(OrganizationalAccessService::class);

        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->with(['roles', 'protectedArea:id,name'])
                ->latest()
                ->paginate(15)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'office_designated' => $user->office_designated, // Ã°Å¸Å¡â‚¬ Gidugang para makita sa listahan
                    'section' => $user->section,                     // Ã°Å¸Å¡â‚¬ Gidugang para makita sa listahan (CDS o MES)
                    'unit_assignment' => $user->unit_assignment,
                    'effective_category' => app(OrganizationalAccessService::class)->effectiveCategory($user),
                'operational_group' => $organization->operationalGroupForCategory(app(OrganizationalAccessService::class)->effectiveCategory($user), $user->unit_assignment),
                    'protected_area_id' => $user->protected_area_id,
                    'role' => $user->roles->first()?->name,
                    'account_role' => $organization->accountRole($user),
                    'protected_area_name' => $user->protectedArea?->name,
                    'access_configured' => $user->roles->contains(fn ($role): bool => $role->name !== 'no_role'),
                    'is_approved' => (bool) $user->is_approved,
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
        $organization = app(OrganizationalAccessService::class);

        return Inertia::render('Admin/Users/Create', [
            'operationalGroups' => $organization->operationalGroups(),
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name', 'short_name']),
            'offices' => app(OrganizationalAccessService::class)->officeOptions(),
            'accountRoles' => $organization->accountRoleOptions(),
        ]);
    }

    /**
     * Store a new user account and its role.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);
        $organization = app(OrganizationalAccessService::class);

        $data = $request->validated();
        $role = $this->resolveInternalRole($data['account_role'] ?? null, $data['role'] ?? null);
        unset($data['account_role'], $data['role'], $data['operational_group']);
        $data = $organization->normalizeAssignment($data);

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
        $organization = app(OrganizationalAccessService::class);

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'office_designated' => $user->office_designated, // Ã°Å¸Å¡â‚¬ Gidugang para ma-load sa edit form
                'section' => $user->section,                     // Ã°Å¸Å¡â‚¬ Gidugang para ma-load sa edit form
                    'protected_area_id' => $user->protected_area_id,
                    'role' => $user->roles->first()?->name,
                'account_role' => $organization->accountRole($user),
                'unit_assignment' => $user->unit_assignment,
                'effective_category' => app(OrganizationalAccessService::class)->effectiveCategory($user),
                'operational_group' => $organization->operationalGroupForCategory(app(OrganizationalAccessService::class)->effectiveCategory($user), $user->unit_assignment),
                'is_approved' => (bool) $user->is_approved,
                'is_active' => (bool) $user->is_active,
            ],
            'operationalGroups' => $organization->operationalGroups(),
            'protectedAreas' => ProtectedArea::query()->orderBy('name')->get(['id', 'name', 'short_name']),
            'offices' => app(OrganizationalAccessService::class)->officeOptions(),
            'accountRoles' => $organization->accountRoleOptions(),
        ]);
    }

    /**
     * Update a user account, status (approval), and role.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $organization = app(OrganizationalAccessService::class);

        $data = $request->validated();
        $oldRole = $user->roles()->first()?->name;
        $before = $user->only(['name', 'email', 'office_designated', 'section', 'unit_assignment', 'protected_area_id', 'is_active']);
        $role = $this->resolveInternalRole(
            $data['account_role'] ?? null,
            $data['role'] ?? null,
            $user->roles()->first()?->name,
        );
        unset($data['account_role'], $data['role']);
        $data = $organization->normalizeAssignment($data);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (($data['is_active'] ?? null) === true && ! $user->is_approved) {
            throw ValidationException::withMessages(['is_active' => 'Approve the account before activating it.']);
        }

        if (! $user->is_active && ($data['is_active'] ?? false)) {
            $activationError = $this->activationError($user, $data, $role);
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

    /** Activate a configured account after validating its business scope. */
    public function activate(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        if (! $user->is_approved) {
            return back()->with('error', 'Approve the account before activating it.');
        }

        if ($user->is_active) {
            return back()->with('error', 'This account is already active.');
        }

        $organization = app(OrganizationalAccessService::class);
        $assignment = $organization->normalizeAssignment([
            'unit_assignment' => $user->unit_assignment,
            'section' => $user->section,
            'office_designated' => $user->office_designated,
            'protected_area_id' => $user->protected_area_id,
        ]);
        $activationError = $this->activationError($user, $assignment);
        if ($activationError) {
            return back()->with('error', $activationError);
        }

        DB::transaction(function () use ($user, $assignment): void {
            $user->update([
                'unit_assignment' => $assignment['unit_assignment'],
                'section' => $assignment['section'],
                'office_designated' => $assignment['office_designated'] ?? null,
                'protected_area_id' => $assignment['protected_area_id'] ?? null,
                'is_active' => true,
            ]);
        });

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

    /** Approve and activate a pending account without changing its access data. */
    public function approve(User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        if ($user->is_approved) {
            return back()->with('error', 'This account is already approved.');
        }

        $before = $user->only(['is_approved', 'is_active']);

        DB::transaction(function () use ($user): void {
            $user->update([
                'is_approved' => true,
                'is_active' => true,
            ]);
        });

        app(AuditLogService::class)->record(
            'user_management',
            'User Approved',
            User::class,
            $user->id,
            'User Management',
            'Approved a pending user account.',
            [
                'before' => $before,
                'after' => $user->fresh()->only(['is_approved', 'is_active']),
            ],
        );

        return to_route('admin.users.index')->with('success', 'User account approved successfully.');
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

    private function resolveInternalRole(?string $accountRole, ?string $legacyRole = null, ?string $existingRole = null): string
    {
        $organization = app(OrganizationalAccessService::class);
        $businessRole = trim((string) $accountRole);

        if ($businessRole === '') {
            $businessRole = in_array($legacyRole, ['CDS Admin', 'Super Admin'], true)
                ? OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN
                : OrganizationalAccessService::ACCOUNT_ROLE_USER;
        }

        if (! in_array($businessRole, [OrganizationalAccessService::ACCOUNT_ROLE_USER, OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN], true)) {
            throw ValidationException::withMessages(['account_role' => 'Select a valid account role.']);
        }

        if ($businessRole === OrganizationalAccessService::ACCOUNT_ROLE_SUPER_ADMIN) {
            return $this->firstExistingRole(['Super Admin', 'CDS Admin'])
                ?? throw ValidationException::withMessages(['account_role' => 'No configured Super Admin permission profile is available.']);
        }

        if ($legacyRole !== null && $organization->categoryForRole($legacyRole) !== null) {
            throw ValidationException::withMessages(['role' => 'User categories are not Spatie roles. Select a category separately.']);
        }

        if ($legacyRole !== null && $this->roleExists($legacyRole) && ! in_array($legacyRole, ['CDS Admin', 'Super Admin'], true)) {
            return $legacyRole;
        }

        if ($existingRole !== null && $this->roleExists($existingRole) && ! in_array($existingRole, ['CDS Admin', 'Super Admin'], true) && $organization->categoryForRole($existingRole) === null) {
            return $existingRole;
        }

        return $this->firstExistingRole(['no_role'])
            ?? throw ValidationException::withMessages(['account_role' => 'No User permission profile is available.']);
    }

    private function isOrganizationalValue(string $value, OrganizationalAccessService $organization): bool
    {
        $normalized = mb_strtolower(trim($value));

        if ($organization->categoryForRole($value) !== null
            || in_array($normalized, ['mhrws', 'bpl', 'bmsfr', 'apl', 'mpl', 'pbpls', 'pamo'], true)
            || str_starts_with($normalized, 'cenro ')
            || str_starts_with($normalized, 'penro ')) {
            return true;
        }

        return ProtectedArea::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->orWhereRaw("LOWER(COALESCE(short_name, '')) = ?", [$normalized])
            ->exists();
    }

    private function firstExistingRole(array $names): ?string
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->orderByRaw("CASE name WHEN 'Super Admin' THEN 1 WHEN 'CDS Admin' THEN 2 WHEN 'Viewer' THEN 3 WHEN 'no_role' THEN 4 ELSE 5 END")
            ->value('name');
    }

    private function roleExists(string $role): bool
    {
        return Role::query()->where('guard_name', 'web')->where('name', $role)->exists();
    }
    private function activationError(User $user, array $data = [], ?string $roleName = null): ?string
    {
        $organization = app(OrganizationalAccessService::class);
        $roleName ??= $user->roles()->first()?->name;

        // A pending normal User may legitimately retain the temporary no_role
        // profile. Business readiness is determined by Unit, User Category,
        // and the applicable PA/office scope below.
        if (in_array($roleName, ['CDS Admin', 'Super Admin'], true)) return null;

        $unit = strtolower(trim((string) (array_key_exists('unit_assignment', $data) ? $data['unit_assignment'] : $user->unit_assignment)));
        $category = (string) ($data['section'] ?? $user->section);
        $office = array_key_exists('office_designated', $data) ? $data['office_designated'] : $user->office_designated;
        $protectedAreaId = array_key_exists('protected_area_id', $data) ? $data['protected_area_id'] : $user->protected_area_id;

        if (! in_array($category, $organization->operationalCategories(), true)) {
            return 'Please complete the user\'s access role and organizational assignment before activating this account.';
        }

        try {
            $organization->validateAssignment($unit !== '' ? $unit : null, $category, $office, $protectedAreaId);
        } catch (ValidationException) {
            return 'Please complete the user\'s access role and organizational assignment before activating this account.';
        }

        return null;
    }
}
