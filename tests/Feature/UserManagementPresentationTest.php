<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('user management uses clickable rows instead of a table action column', function (): void {
    $page = File::get(resource_path('js/Pages/Admin/Users/Index.jsx'));
    $columns = Str::before(Str::after($page, 'const columns = ['), '];');

    expect($columns)
        ->toContain("label: 'Name'")
        ->toContain("label: 'Account Status'")
        ->not->toContain("key: 'actions'")
        ->not->toContain('>Edit<')
        ->not->toContain('>Delete<')
        ->not->toContain('>Activate<')
        ->not->toContain('>Deactivate<');

    expect($page)
        ->toContain('onRowClick={setSelectedUser}')
        ->toContain('title="User Details"')
        ->toContain('Click any row to view user details and administrative actions');
});

test('user details exposes the saved assignment and state-specific administrative actions', function (): void {
    $page = File::get(resource_path('js/Pages/Admin/Users/Index.jsx'));

    expect($page)
        ->toContain("if (!user?.is_approved) return { label: 'Pending Approval', variant: 'pending' }")
        ->toContain("if (user?.is_active) return { label: 'Active', variant: 'active' }")
        ->toContain('User Category')
        ->toContain('Office Designated')
        ->toContain('Protected Area / PAMO Assignment')
        ->toContain('Registration Date')
        ->toContain('Last Updated')
        ->toContain('Activate Account')
        ->toContain('Approve Account')
        ->toContain('Deactivate Account')
        ->toContain('Approval Status')
        ->toContain('Delete User')
        ->toContain('router.patch(`/admin/users/${userToActivate.id}/activate`');
});

test('user management supplies the detail view with an updated date and protected area name', function (): void {
    $controller = File::get(app_path('Http/Controllers/Admin/UserController.php'));

    expect($controller)
        ->toContain("'protected_area_name' => \$user->protectedArea?->name")
        ->toContain("'created_at' => \$user->created_at?->toDateString()")
        ->toContain("'updated_at' => \$user->updated_at?->toDateString()");
});

test('user access forms separate Spatie role from organizational assignments', function (): void {
    $form = File::get(resource_path('js/Pages/Admin/Users/Form.jsx'));
    $controller = File::get(app_path('Http/Controllers/Admin/UserController.php'));

    expect($form)
        ->toContain('id="form-account-role"')
        ->toContain('label="Account Role"')
        ->toContain('accountRoleOptions.map')
        ->toContain('label="User Category"')
        ->toContain('label="CENRO Office"')
        ->toContain('Protected Area / PAMO assignment');

    expect($controller)
        ->toContain("'accountRoles' => \$organization->accountRoleOptions()")
        ->toContain("'offices' => app(OrganizationalAccessService::class)->officeOptions()")
        ->toContain('resolveInternalRole')
        ->not->toContain("syncRoles([\$roleForCategory])");
});

test('user edit form exposes status as read-only and keeps activation in administrative actions', function (): void {
    $form = File::get(resource_path('js/Pages/Admin/Users/Form.jsx'));

    expect($form)
        ->not->toContain('id="form-account-status"')
        ->not->toContain("label='Account status'")
        ->not->toContain('is_active:')
        ->toContain('password_confirmation');

    $index = File::get(resource_path('js/Pages/Admin/Users/Index.jsx'));

    expect($index)
        ->toContain('/approve')
        ->toContain('Deactivate Account')
        ->toContain('Activate Account');
});
