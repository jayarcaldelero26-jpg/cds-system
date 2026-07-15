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
                    'role' => $user->roles->first()?->name,
                    'is_active' => (bool) $user->is_active, // Gi-force nga boolean para sa frontend toggle
                    'created_at' => $user->created_at?->toDateString(),
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
        $role = $data['role'];
        unset($data['role']);

        $data['password'] = Hash::make($data['password']);

        // Kon bag-ong rehistro o hinimo, i-default og false (0) para pending approval pa sila,
        // gawas kon gi-set nimo sa porma nga active na daan.
        $data['is_active'] = $data['is_active'] ?? false;

        $user = User::create($data);
        $user->syncRoles([$role]);

        return to_route('admin.users.index')->with('status', 'user-created');
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
        $role = $data['role'];
        unset($data['role']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        // Sigurohon nga ma-save ang toggle status sa is_active (true/false)
        $data['is_active'] = filter_var($data['is_active'] ?? $user->is_active, FILTER_VALIDATE_BOOLEAN);

        $user->update($data);
        $user->syncRoles([$role]);

        return to_route('admin.users.index')->with('status', 'user-updated');
    }

    /**
     * Delete a user account when permitted by the policy.
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return to_route('admin.users.index')->with('status', 'user-deleted');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    private function availableRoles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();
    }
}
