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
        ->toContain('User Category')
        ->toContain('Office Designated')
        ->toContain('Protected Area / PAMO Assignment')
        ->toContain('Registration Date')
        ->toContain('Last Updated')
        ->toContain('Activate Account')
        ->toContain('Deactivate Account')
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

test('user access forms configure category without a separate role selector', function (): void {
    $form = File::get(resource_path('js/Pages/Admin/Users/Form.jsx'));
    $controller = File::get(app_path('Http/Controllers/Admin/UserController.php'));

    expect($form)
        ->toContain('label="User category"')
        ->not->toContain('form-role')
        ->not->toContain('roles.map')
        ->not->toContain("label=\"Role\"");

    expect($controller)
        ->toContain('roleForCategory($data[\'section\'])')
        ->not->toContain("'roles' => \$this->availableRoles()");
});
