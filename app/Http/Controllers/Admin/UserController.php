<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display all user accounts.
     */
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::query()->with('roles')->latest()->paginate(15),
        ]);
    }

    /**
     * Display the user creation form.
     */
    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', [
            'roles' => $this->availableRoles(),
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
        $data['is_active'] = $data['is_active'] ?? true;

        $user = User::create($data);
        $user->syncRoles([$role]);

        return to_route('admin.users.index')->with('status', 'user-created');
    }

    /**
     * Display the user edit form.
     */
    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'roles' => $this->availableRoles(),
        ]);
    }

    /**
     * Update a user account, status, and role.
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
