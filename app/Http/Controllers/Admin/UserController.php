<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use App\Services\AuditLogService;

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
                ->with('roles')
                ->latest()
                ->paginate(15)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'office_designated' => $user->office_designated, // 🚀 Gidugang para makita sa listahan
                    'section' => $user->section,                     // 🚀 Gidugang para makita sa listahan (CDS o MES)
                    'role' => $user->roles->first()?->name,
                    'is_active' => (bool) $user->is_active,
                    'created_at' => $user->created_at?->toDateString(),
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
            'roles' => $this->availableRoles()->pluck('name')->values(),
        ]);
    }

    /**
     * Store a new user account and its role.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $role = $data['role'] ?? null;
        if ($role) {
            unset($data['role']);
        }

        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = $data['is_active'] ?? false;

        $user = User::create($data);
        if ($role) {
            $user->syncRoles([$role]);
        }
        app(AuditLogService::class)->record('user_management', 'User Created', User::class, $user->id, 'User Management', 'Created a user account.', ['role' => $role, 'section' => $user->section]);

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
                'role' => $user->roles()->first()?->name,
                'is_active' => (bool) $user->is_active,
            ],
            'roles' => $this->availableRoles()->pluck('name')->values(),
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
        $before = $user->only(['name', 'email', 'office_designated', 'section', 'is_active']);
        $role = $data['role'] ?? null;
        if ($role) {
            unset($data['role']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $user->update($data);

        if ($role) {
            $user->syncRoles([$role]);
        }
        app(AuditLogService::class)->record('user_management', 'User Updated', User::class, $user->id, 'User Management', 'Updated a user account.', ['before' => $before, 'after' => $user->fresh()->only(array_keys($before)), 'old_role' => $oldRole, 'new_role' => $role]);

        return to_route('admin.users.index')->with('success', 'User updated successfully.');
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    private function availableRoles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['CDS Admin', 'Technical Staff', 'Viewer'])
            ->orderBy('name')
            ->get();
    }
}
