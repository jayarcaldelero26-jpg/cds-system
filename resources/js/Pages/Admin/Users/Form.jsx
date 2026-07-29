import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import Card from '../../../Components/Card';
import PageHeader from '../../../Components/PageHeader';
import PrimaryButton from '../../../Components/PrimaryButton';
import FormField from '../../../Components/FormField';

export default function Form({ title, user, roles }) {
    const isEdit = Boolean(user);
    const [showSuccessModal, setShowSuccessModal] = useState(false);

    const form = useForm({
        name: user?.name || '',
        email: user?.email || '',
        office_designated: user?.office_designated || '',
        section: user?.section || '',
        password: '',
        password_confirmation: '',
        role: user?.role || '',
        is_active: user?.is_active ?? true
    });

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/admin/users/${user.id}`, {
                onSuccess: () => setShowSuccessModal(true),
            });
        } else {
            form.post('/admin/users', {
                onSuccess: () => setShowSuccessModal(true),
            });
        }
    };

    return (
        <AuthenticatedLayout title={title}>
            <PageHeader
                title={title}
                description={isEdit ? 'Update the account details, office, section, access role, and account status.' : 'Create a system user, specify their office/section, and assign their access role.'}
                actions={<Link href="/admin/users" className="text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400">← Back to users</Link>}
            />

            <Card className="mt-6 max-w-3xl">
                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField
                            id="name"
                            label="Name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            error={form.errors.name}
                            required
                        />
                        <FormField
                            id="email"
                            label="Email address"
                            type="email"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            error={form.errors.email}
                            required
                        />

                        <FormField
                            id="office_designated"
                            label="Office Designated (e.g. CENRO Mati, PENRO Davao Oriental)"
                            value={form.data.office_designated}
                            onChange={(event) => form.setData('office_designated', event.target.value)}
                            error={form.errors.office_designated}
                            required
                        />

                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Section
                            <select
                                required
                                className="mt-1.5 block w-full rounded-ui border-gray-300 bg-white shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                                value={form.data.section}
                                onChange={(event) => form.setData('section', event.target.value)}
                            >
                                <option value="">Select section</option>
                                <option value="CDS">Conservation Development Section (CDS)</option>
                                <option value="MES">Monitoring and Enforcement Section (MES)</option>
                            </select>
                            {form.errors.section && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.section}</p>}
                        </label>

                        <FormField
                            id="password"
                            label={isEdit ? 'New password' : 'Password'}
                            type="password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            error={form.errors.password}
                            required={!isEdit}
                            hint={isEdit ? 'Leave blank to keep the current password.' : undefined}
                        />
                        <FormField
                            id="password_confirmation"
                            label="Confirm password"
                            type="password"
                            value={form.data.password_confirmation}
                            onChange={(event) => form.setData('password_confirmation', event.target.value)}
                            error={form.errors.password_confirmation}
                            required={!isEdit}
                        />

                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Role
                            <select
                                required
                                className="mt-1.5 block w-full rounded-ui border-gray-300 bg-white shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                                value={form.data.role}
                                onChange={(event) => form.setData('role', event.target.value)}
                            >
                                <option value="">Select a role</option>
                                {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                            </select>
                            {form.errors.role && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.role}</p>}
                        </label>

                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Account status
                            <select
                                className="mt-1.5 block w-full rounded-ui border-gray-300 bg-white shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                                value={form.data.is_active ? '1' : '0'}
                                onChange={(event) => form.setData('is_active', event.target.value === '1')}
                            >
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </label>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : isEdit ? 'Save changes' : 'Create user'}
                        </PrimaryButton>
                        <Link href="/admin/users" className="rounded-ui px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">
                            Cancel
                        </Link>
                    </div>
                </form>
            </Card>

            {/* 🚀 Success Pop-up Modal / Dialog */}
            {showSuccessModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-900 dark:border dark:border-gray-800">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300">
                                ✓
                            </div>
                            <div>
                                <h3 className="text-base font-semibold text-gray-900 dark:text-white">
                                    {isEdit ? 'User Updated Successfully' : 'User Created Successfully'}
                                </h3>
                                <p className="text-sm text-gray-500 dark:text-gray-400">
                                    {isEdit ? 'The user details have been successfully updated.' : 'The new user account has been successfully created.'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-6 flex justify-end">
                            <Link
                                href="/admin/users"
                                className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700"
                            >
                                Okay
                            </Link>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
